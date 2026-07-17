<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\RunnerFactory;
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
     * Restores a database backup. DRY-RUN by default (spec §5-2).
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
        // pgsql (etc.) can leave the target database partially imported or unusable (Codex review B6)
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
        // (Codex review G1, docs/restore.md)
        $this->stdout("REQUIRED: block external traffic at the web-server/load-balancer level before proceeding — the DB-based maintenance flag is briefly overwritten during import.\n", Console::FG_YELLOW);
        if (!$this->force && !$this->confirm("Restore run {$runId} over the CURRENT database?")) {
            return ExitCode::OK;
        }

        $lock = $factory->lock();
        // Use a process-specific suffix so lock diagnostics distinguish concurrent
        // restore attempts for the same runId (Codex review B2)
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
        $sqlPath = $work . "/restore-{$runId}.sql";

        try {
            // 1. Download + integrity chain (destructive steps only after every check passes — spec §4)
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
            // Path traversal guard: only the fixed entry name 'backup.sql' is accepted (spec §5-5)
            if ($zip->locateName('backup.sql') === false) {
                throw new \RuntimeException('Archive does not contain backup.sql.');
            }
            copy("zip://{$zipPath}#backup.sql", $sqlPath);
            $zip->close();
            $head = (string)file_get_contents($sqlPath, false, null, 0, 512);
            if ($head === '' || (!str_contains($head, '--') && stripos($head, 'SET') === false && stripos($head, 'CREATE') === false)) {
                throw new \RuntimeException('SQL sanity check failed — file does not look like a SQL dump.');
            }

            // 2. Maintenance mode ON (required by spec §5-2). If the operator had already
            // enabled it we don't own the flag — record it as the state to restore
            // after import (Codex review B5/F1)
            $wasAlreadyInMaintenance = (bool)\Craft::$app->getInfo()->maintenance;
            if (!$wasAlreadyInMaintenance) {
                if (!\Craft::$app->enableMaintenanceMode()) {
                    throw new \RuntimeException('Could not enable maintenance mode — refusing to restore.');
                }
                $maintenanceEnabled = true;
            }

            // 3. Pre-restore local dump = rollback point (internal primitive under the same lock ownership)
            $lock->progress('rollback-dump');
            $rollback = $work . '/pre-restore-' . date('YmdHis') . '.sql';
            $this->stdout("Taking pre-restore dump: {$rollback}\n");
            \Craft::$app->getDb()->backupTo($rollback);

            // 4. import
            $lock->progress('import');
            $this->stdout("Restoring database...\n");
            try {
                \Craft::$app->getDb()->restore($sqlPath);
                // The import overwrites info.maintenance with the backup-time value —
                // switch it back ON immediately to minimize the exposure window. The only
                // complete guarantee is web-level blocking (made mandatory above) (Codex review G1)
                \craft\helpers\Db::update(Table::INFO, ['maintenance' => true], ['id' => 1]);
            } catch (\Throwable $e) {
                $this->stderr("Restore failed: {$e->getMessage()}\nRolling back from {$rollback}...\n", Console::FG_RED);
                try {
                    \Craft::$app->getDb()->restore($rollback);
                } catch (\Throwable $rollbackError) {
                    // Never expose a partially imported DB: if even the rollback fails,
                    // keep maintenance mode on and defer to the operator (Codex review B5)
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
                // Migration/schema consistency check (spec §5-2). Craft::$app->getInfo()
                // returns the in-process cache (the pre-restore Info), so read the DB directly
                $restoredSchema = (string)(new \craft\db\Query())
                    ->select('schemaVersion')->from(Table::INFO)->where(['id' => 1])->scalar();
                if ($restoredSchema !== $entry->schemaVersion) {
                    $this->stderr("WARNING: restored schemaVersion ({$restoredSchema}) differs from catalog ({$entry->schemaVersion}). Run `craft migrate/all` and verify the site.\n", Console::FG_YELLOW);
                }
                // The import overwrote the maintenance flag with the backup-time value, so
                // restore the prior state via a direct DB update. disableMaintenanceMode()
                // is not used: it would write the pre-restore version/schemaVersion back
                // through the cached Info (Codex review R1/F1)
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
            // Never leave raw SQL / zip behind, even on failure (Codex review S12). The
            // rollback point (pre-restore dump) is kept intentionally
            @unlink($zipPath);
            @unlink($sqlPath);
            if ($keepMaintenance) {
                // The imported DB carries the backup-time flag value (usually false) —
                // since we declare maintenance "kept ON", force it ON in the DB too (Codex review F1)
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
}
