<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\CraftDatabaseDumper;
use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\engine\DumpFormat;
use cdgrph\offsite\engine\LockUnavailableException;
use craft\console\Controller;
use craft\db\Table;
use yii\console\ExitCode;
use yii\helpers\Console;

final class RestoreController extends Controller
{
    public bool $execute = false;
    public bool $force = false;
    public bool $skipQueueCheck = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['execute', 'force', 'skipQueueCheck']);
    }

    /**
     * Restores a database backup. DRY-RUN by default.
     * Guards: catalog integrity chain → site/version preflight → maintenance
     * mode + queue check → pre-restore local dump → import → post checks.
     */
    public function actionDb(string $runId): int
    {
        $factory = new RunnerFactory();
        if ($factory->settingsErrors() !== []) {
            $this->stderr("Settings invalid.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $catalog = $factory->catalog();
        $entry = $catalog->get($runId);
        $info = \Craft::$app->getInfo();
        $fmt = DumpFormat::tryFrom($entry->dumpFormat);
        if ($fmt === null) {
            $this->stderr("Catalog entry carries unknown dumpFormat '{$entry->dumpFormat}' — refusing to restore.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Restores cannot run at all while Craft's restoreCommand is false —
        // the connection throws before executing anything. Fail up front
        if (\Craft::$app->getConfig()->getGeneral()->restoreCommand === false) {
            $this->stderr("Craft's restoreCommand config setting is false — restores are disabled in this environment.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // The pre-restore rollback dump is mandatory in this flow, so a disabled
        // backup command matters just as much: backupTo() would throw mid-run,
        // after maintenance is already enabled. Refuse up front instead
        if (CraftDatabaseDumper::backupCommandDisabled()) {
            $this->stderr("Craft's backupCommand config setting is false — the pre-restore rollback dump cannot be taken, so restores cannot run in this environment.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // The pre-restore rollback dump is taken under the *currently configured*
        // backupCommandFormat. tar/directory (or custom on pre-5.2 Craft) would
        // produce a rollback file the plain/custom content sniff cannot dispatch,
        // breaking the automatic rollback — refuse before anything runs
        $unsupportedFormat = CraftDatabaseDumper::unsupportedBackupFormat();
        if ($unsupportedFormat !== null) {
            $this->stderr(CraftDatabaseDumper::unsupportedFormatMessage($unsupportedFormat) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Craft dispatches pg_restore only through its *default* restore command:
        // a string restoreCommand replaces it outright, and a Closure receives the
        // built command and may replace it too. Either way the pg_restore dispatch
        // is not guaranteed — refuse instead (false is handled above)
        if ($fmt === DumpFormat::Custom && self::restoreCommandBlocksPgRestore()) {
            $this->stderr("Restoring a custom-format backup requires Craft's default restore command (pg_restore dispatch), but the restoreCommand config setting is overridden in this environment.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // The rollback dump follows the *currently resolved* format: when the site
        // resolves to custom dumps, the rollback point will need pg_restore too,
        // which the same override cannot guarantee — refuse before anything runs
        // (the post-dump re-check below remains as defense in depth)
        if (CraftDatabaseDumper::resolvedBackupFormat() === 'custom' && self::restoreCommandBlocksPgRestore()) {
            $this->stderr("The pre-restore rollback dump would be a custom archive, but the restoreCommand config setting is overridden in this environment — the automatic rollback could not dispatch pg_restore.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // --- Preflight report (also runs in dry-run) ---
        $checks = [
            'site UID matches' => $entry->siteUid === (string)$info->uid,
            'Craft version matches' => $entry->craftVersion === \Craft::$app->getVersion(),
            'schema version matches' => $entry->schemaVersion === (string)$info->schemaVersion,
            'DB driver matches' => $entry->dbDriver === \Craft::$app->getDb()->getDriverName(),
        ];
        $this->stdout("Restore plan for run {$runId} (object: {$entry->objectKey}, {$entry->sizeBytes} bytes):\n");
        $mismatch = false;
        foreach ($checks as $label => $ok) {
            $this->stdout(sprintf("  [%s] %s\n", $ok ? 'ok' : 'MISMATCH', $label), $ok ? Console::FG_GREEN : Console::FG_YELLOW);
            $mismatch = $mismatch || !$ok;
        }

        if (!$this->execute) {
            $this->stdout("\nDry-run only. Re-run with --execute to restore.\n", Console::FG_CYAN);
            return ExitCode::OK;
        }
        // A driver mismatch is rejected even with --force: piping a mysql dump into
        // pgsql (etc.) can leave the target database partially imported or unusable
        if (!$checks['DB driver matches']) {
            $this->stderr("DB driver mismatch (backup: {$entry->dbDriver}, this environment: " . \Craft::$app->getDb()->getDriverName() . ") cannot be overridden.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if ($mismatch && !$this->force) {
            $this->stderr("Preflight mismatches found. Use --force to override.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if (!$this->skipQueueCheck) {
            $reserved = (new \craft\db\Query())->from(Table::QUEUE)->where(['not', ['timeUpdated' => null]])->count();
            if ($reserved > 0) {
                $this->stderr("{$reserved} queue job(s) are currently reserved. Stop queue workers first, or pass --skip-queue-check.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        // The maintenance flag lives inside the DB and is briefly overwritten during
        // import, so external traffic blocking is a hard requirement for integrity
        // (see docs/restore.md)
        $this->stdout("REQUIRED: block external traffic at the web-server/load-balancer level before proceeding — the DB-based maintenance flag is briefly overwritten during import.\n", Console::FG_YELLOW);
        if (!$this->force && !$this->confirm("Restore run {$runId} over the CURRENT database?")) {
            return ExitCode::OK;
        }

        $lock = $factory->lock();
        // Use a process-specific suffix so lock diagnostics distinguish concurrent
        // restore attempts for the same runId
        $owner = 'restore:' . $runId . ':' . bin2hex(random_bytes(4));
        try {
            $lock->acquire($owner);
        } catch (LockUnavailableException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $work = $factory->workDir();
        $maintenanceEnabled = false;
        $keepMaintenance = false;
        $zipPath = $work . "/restore-{$runId}.zip";
        $dumpPath = $work . "/restore-{$runId}" . $fmt->extension();

        try {
            // 1. Download + integrity chain (destructive steps only after every check passes)
            $lock->progress('download');
            $this->stdout("Downloading {$entry->objectKey}...\n");
            $factory->destination()->getToFile($entry->objectKey, $zipPath);
            if (hash_file('sha256', $zipPath) !== $entry->sha256) {
                throw new \RuntimeException('SHA-256 mismatch — refusing to restore.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
                throw new \RuntimeException('Zip CRC check failed — refusing to restore.');
            }
            // Path traversal guard: only the entry name fixed by the recorded dump
            // format is accepted
            if ($zip->locateName($fmt->entryName()) === false) {
                throw new \RuntimeException("Archive does not contain {$fmt->entryName()}.");
            }
            copy("zip://{$zipPath}#{$fmt->entryName()}", $dumpPath);
            $zip->close();
            // Content sanity check doubles as catalog-vs-content cross-validation:
            // the entry carries the format the manifest promised
            CraftDatabaseDumper::assertDumpLooksLike($dumpPath, $fmt);
            // Dispatch decision (psql vs pg_restore) before any destructive step:
            // a custom backup on a pre-5.2 install must abort here, not after the
            // rollback dump. Also resets any restoreFormat left over in this process
            self::applyRestoreFormat($fmt);

            // 2. Maintenance mode ON (mandatory before any destructive step). If the
            // operator had already enabled it we don't own the flag — record it as
            // the state to restore after import
            $wasAlreadyInMaintenance = (bool)\Craft::$app->getInfo()->maintenance;
            if (!$wasAlreadyInMaintenance) {
                if (!\Craft::$app->enableMaintenanceMode()) {
                    throw new \RuntimeException('Could not enable maintenance mode — refusing to restore.');
                }
                $maintenanceEnabled = true;
            }

            // 3. Pre-restore local dump = rollback point (internal primitive under the same lock ownership)
            $lock->progress('rollback-dump');
            // Naming only: the extension follows the *configured* format here — a
            // backupCommand override can still diverge the actual content, which is
            // why every dispatch decision below sniffs the file instead
            $rollbackNameFmt = DumpFormat::fromResolved(CraftDatabaseDumper::resolvedBackupFormat());
            $rollback = $work . '/pre-restore-' . date('YmdHis') . $rollbackNameFmt->extension();
            $this->stdout("Taking pre-restore dump: {$rollback}\n");
            \Craft::$app->getDb()->backupTo($rollback);
            // The rollback point is only as good as its content: a string/Closure
            // backupCommand override can produce something that is neither a plain
            // SQL dump nor a custom archive (tar, empty, corrupt). Refuse before
            // the destructive import — the current DB is still untouched here
            $rollbackFmt = CraftDatabaseDumper::detectDumpFormat($rollback);
            CraftDatabaseDumper::assertDumpLooksLike($rollback, $rollbackFmt);
            CraftDatabaseDumper::assertDumpComplete($rollback, $rollbackFmt);
            // The rollback must also be *dispatchable* — verify now, before the
            // import: if this check only lived in the failure handler, an
            // undispatchable rollback (override, pre-5.2, driver mismatch) would
            // throw there and the rollback would never even be attempted
            if ($reason = self::restoreDispatchRefusal($rollbackFmt)) {
                throw new \RuntimeException($reason);
            }
            // A custom rollback dump would need pg_restore, which neither a string
            // restoreCommand (replaces the command outright) nor a Closure (may
            // replace the built one) can guarantee — the automatic rollback could
            // not run, so never start the destructive import
            if ($rollbackFmt === DumpFormat::Custom && self::restoreCommandBlocksPgRestore()) {
                throw new \RuntimeException('The pre-restore rollback dump is a custom archive, but the restoreCommand config setting is overridden in this environment — the automatic rollback could not dispatch pg_restore. Remove the override before restoring.');
            }

            // 4. import (restoreFormat was already set in step 1)
            $lock->progress('import');
            $this->stdout("Restoring database...\n");
            try {
                \Craft::$app->getDb()->restore($dumpPath);
                // The import overwrites info.maintenance with the backup-time value —
                // switch it back ON immediately to minimize the exposure window. The only
                // complete guarantee is web-level blocking (made mandatory above)
                \craft\helpers\Db::update(Table::INFO, ['maintenance' => true], ['id' => 1]);
            } catch (\Throwable $e) {
                $this->stderr("Restore failed: {$e->getMessage()}\nRolling back from {$rollback}...\n", Console::FG_RED);
                try {
                    // The rollback file's sniffed format (which may differ from the
                    // imported backup's) drives the dispatch. Setting the format
                    // explicitly (rather than reusing the main import's) is what
                    // keeps a plain rollback dump off pg_restore on a custom site
                    self::applyRestoreFormat($rollbackFmt);
                    \Craft::$app->getDb()->restore($rollback);
                } catch (\Throwable $rollbackError) {
                    // Never expose a partially imported DB: if even the rollback fails,
                    // keep maintenance mode on and defer to the operator
                    $keepMaintenance = true;
                    $this->stderr(
                        "CRITICAL: rollback also failed ({$rollbackError->getMessage()}). The database may be in a partial state. Maintenance mode is kept ON — restore manually from {$rollback}.\n",
                        Console::FG_RED
                    );
                }
                throw $e;
            }

            // 5. Post-checks + cache invalidation. Keep maintenance mode on if they fail
            try {
                $lock->progress('post-check');
                \Craft::$app->getCache()->flush();
                \Craft::$app->getDb()->getSchema()->refresh();
                // Migration/schema consistency check. Craft::$app->getInfo()
                // returns the in-process cache (the pre-restore Info), so read the DB directly
                $restoredSchema = (string)(new \craft\db\Query())
                    ->select('schemaVersion')->from(Table::INFO)->where(['id' => 1])->scalar();
                if ($restoredSchema !== $entry->schemaVersion) {
                    $this->stderr("WARNING: restored schemaVersion ({$restoredSchema}) differs from catalog ({$entry->schemaVersion}). Run `craft migrate/all` and verify the site.\n", Console::FG_YELLOW);
                }
                // The import overwrote the maintenance flag with the backup-time value, so
                // restore the prior state via a direct DB update. disableMaintenanceMode()
                // is not used: it would write the pre-restore version/schemaVersion back
                // through the cached Info
                \craft\helpers\Db::update(Table::INFO, ['maintenance' => $wasAlreadyInMaintenance], ['id' => 1]);
                $maintenanceEnabled = false;
            } catch (\Throwable $e) {
                $keepMaintenance = true;
                $this->stderr("Post-restore checks failed ({$e->getMessage()}). Maintenance mode is kept ON — verify the site manually.\n", Console::FG_RED);
                throw $e;
            }
            $this->stdout("Post-restore checks passed. Pre-restore dump kept at {$rollback}.\n", Console::FG_GREEN);
            return ExitCode::OK;
        } finally {
            // Never leave raw dumps / zip behind, even on failure. The rollback point
            // (pre-restore dump) is kept intentionally
            @unlink($zipPath);
            @unlink($dumpPath);
            if ($keepMaintenance) {
                // The imported DB carries the backup-time flag value (usually false) —
                // since we declare maintenance "kept ON", force it ON in the DB too
                try {
                    \craft\helpers\Db::update(Table::INFO, ['maintenance' => true], ['id' => 1]);
                } catch (\Throwable) {
                    $this->stderr("Could not force maintenance flag ON — block traffic at the web-server level until the site is verified.\n", Console::FG_RED);
                }
            } elseif ($maintenanceEnabled) {
                \Craft::$app->disableMaintenanceMode();
            }
            try {
                $lock->release($owner);
            } catch (\Throwable $e) {
                $this->stderr("Lock release failed: {$e->getMessage()}\n", Console::FG_RED);
            }
        }
    }

    /**
     * Whether a restoreCommand override removes the pg_restore dispatch
     * guarantee in this environment: a string replaces Craft's default command
     * outright, and a Closure receives the built command and may replace it
     * too. (false is a separate, louder refusal handled inline.)
     */
    private static function restoreCommandBlocksPgRestore(): bool
    {
        return \Craft::$app->getDb()->getIsPgsql()
            && \Craft::$app->getConfig()->getGeneral()->restoreCommand !== null;
    }

    /**
     * Why the restore binary for $fmt cannot be dispatched in this environment,
     * or null when it can. Mirrors the checks applyRestoreFormat() enforces, so
     * dispatch can be validated *before* the destructive import instead of the
     * failure handler discovering the refusal mid-rollback.
     */
    private static function restoreDispatchRefusal(DumpFormat $fmt): ?string
    {
        if ($fmt !== DumpFormat::Custom) {
            return null; // plain dispatches through psql / the mysql client everywhere
        }
        // A checksum-valid manifest can still be internally inconsistent;
        // never feed a custom archive to a non-pgsql restore path
        if (!\Craft::$app->getDb()->getIsPgsql()) {
            return 'A custom-format backup cannot be restored on a non-PostgreSQL database — the catalog entry records an inconsistent driver/format pair.';
        }
        if (!method_exists(\Craft::$app->getDb()->getSchema(), 'setRestoreFormat')) {
            return 'Restoring a custom-format backup requires Craft 5.2+ (pg_restore dispatch is unavailable).';
        }
        return null;
    }

    /**
     * Tells Craft which restore binary to dispatch before Connection::restore():
     * pgsql picks psql vs pg_restore from the schema's restoreFormat, which only
     * setRestoreFormat() (Craft 5.2+) sets. Called before EVERY restore so no
     * stale value from an earlier import in this process can misroute the next
     * one (e.g. a plain rollback dump onto pg_restore).
     */
    private static function applyRestoreFormat(DumpFormat $fmt): void
    {
        if ($reason = self::restoreDispatchRefusal($fmt)) {
            throw new \RuntimeException($reason);
        }
        if (!\Craft::$app->getDb()->getIsPgsql()) {
            return; // the mysql path never consults restoreFormat
        }
        $schema = \Craft::$app->getDb()->getSchema();
        if (!method_exists($schema, 'setRestoreFormat')) {
            // Pre-5.2 has no per-run control; plain (the only format allowed
            // there — see restoreDispatchRefusal) already dispatches via psql,
            // so there is nothing to set
            return;
        }
        $schema->setRestoreFormat($fmt->value);
    }
}
