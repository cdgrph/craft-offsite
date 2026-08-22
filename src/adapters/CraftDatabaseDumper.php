<?php
declare(strict_types=1);

namespace cdgrph\offsite\adapters;

use cdgrph\offsite\engine\DatabaseDumper;
use cdgrph\offsite\engine\DumpFormat;
use cdgrph\offsite\engine\DumpResult;

/**
 * Delegates to Craft core's native backup (same mysqldump/pg_dump handling
 * and requirements as the CP's own Database Backup utility).
 */
final class CraftDatabaseDumper implements DatabaseDumper
{
    /**
     * The backup format Craft will actually apply to this request: null on
     * MySQL (PostgreSQL-only setting). Resolution is schema-first where the
     * plumbing exists (Schema::getBackupFormat(), 5.2+ — a runtime
     * setBackupFormat() override beats config there), falling back to the
     * backupCommandFormat config. Single resolution point shared by the gate
     * below, dump(), and rollback-dump naming on restore.
     */
    public static function resolvedBackupFormat(): ?string
    {
        if (!\Craft::$app->getDb()->getIsPgsql()) {
            return null;
        }
        $schema = \Craft::$app->getDb()->getSchema();
        if (method_exists($schema, 'getBackupFormat')) {
            return $schema->getBackupFormat();
        }
        $general = \Craft::$app->getConfig()->getGeneral();
        return property_exists($general, 'backupCommandFormat') ? $general->backupCommandFormat : null;
    }

    public static function unsupportedBackupFormat(): ?string
    {
        $format = self::resolvedBackupFormat();
        if ($format === null || $format === 'plain') {
            return null;
        }
        if ($format === 'custom' && self::customFormatSupported()) {
            return null;
        }
        // Never commit anything we cannot restore: tar/directory/unknown values
        // (and custom on pre-5.2 Craft, which lacks the per-run restore-format
        // control needed to dispatch pg_restore). Rejecting at the settings
        // level keeps unrestorable backups from piling up as generations while
        // retention deletes the older, restorable ones
        return $format;
    }

    /**
     * Whether the installed Craft exposes the pgsql format plumbing that makes
     * backupCommandFormat effective (Schema::setBackupFormat, added in 5.2).
     */
    private static function customFormatSupported(): bool
    {
        return method_exists(\Craft::$app->getDb()->getSchema(), 'setBackupFormat');
    }

    /**
     * Whether Craft's backupCommand config setting disables database backups
     * entirely. Mirrors the core stop condition exactly (`=== false`): null
     * and Closure values still produce a dump command, false makes
     * backupTo() throw before any dump is attempted.
     */
    public static function backupCommandDisabled(): bool
    {
        return \Craft::$app->getConfig()->getGeneral()->backupCommand === false;
    }

    public function dump(string $targetDir): DumpResult
    {
        $unsupported = self::unsupportedBackupFormat();
        if ($unsupported !== null) {
            throw new \RuntimeException(self::unsupportedFormatMessage($unsupported));
        }

        $fmt = DumpFormat::fromResolved(self::resolvedBackupFormat());
        $path = rtrim($targetDir, '/') . '/dump-' . date('YmdHis') . $fmt->extension();
        try {
            \Craft::$app->getDb()->backupTo($path);
        } catch (\Throwable $e) {
            // A failed dump can leave a partial file behind, and nothing else
            // sweeps the work dir — remove it so failures cannot accumulate
            // into a disk-space backup outage
            @unlink($path);
            throw $e;
        }
        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException("Craft DB backup produced no file at {$path}.");
        }
        try {
            self::assertDumpLooksLike($path, $fmt);
        } catch (\RuntimeException $e) {
            @unlink($path);
            throw $e;
        }
        return new DumpResult($path, $fmt);
    }

    /**
     * Sniffs a dump file's format from its content (pg_dump custom archives
     * start with the PGDMP magic). Used where the format cannot be known from
     * config alone — e.g. a pre-restore rollback dump produced under whatever
     * backupCommand/backupCommandFormat was active at restore time.
     */
    public static function detectDumpFormat(string $path): DumpFormat
    {
        $head = (string)file_get_contents($path, false, null, 0, 5);
        return $head === 'PGDMP' ? DumpFormat::Custom : DumpFormat::Plain;
    }

    /**
     * Verifies that a produced dump file actually carries the expected
     * format's content, independent of what the config promised (second line
     * of defense: a backupCommand closure or an older Craft can silently
     * change the real output format).
     */
    public static function assertDumpLooksLike(string $path, DumpFormat $format): void
    {
        $head = (string)file_get_contents($path, false, null, 0, 512);
        if ($format === DumpFormat::Custom) {
            if (!str_starts_with($head, 'PGDMP')) {
                throw new \RuntimeException('Dump output is not a pg_dump custom archive (missing PGDMP magic). Check backupCommandFormat / backupCommand.');
            }
            return;
        }
        // A pg_dump custom archive contains strings like SET inside its binary and can
        // slip past the heuristic, so the PGDMP magic is rejected explicitly
        $notSql = str_starts_with($head, 'PGDMP')
            || $head === ''
            || (!str_contains($head, '--') && stripos($head, 'SET') === false && stripos($head, 'CREATE') === false);
        if ($notSql) {
            throw new \RuntimeException('Backup output is not a plain SQL dump. Check backupCommandFormat / backupCommand.');
        }
    }

    /**
     * Human-readable reason why a backupCommandFormat value is rejected.
     * Shared by dump()'s exception and the diagnose output so the two can
     * never drift apart.
     */
    public static function unsupportedFormatMessage(string $format): string
    {
        if ($format === 'custom') {
            // Pre-5.2 Craft lacks Schema::setRestoreFormat(), so Offsite cannot
            // dispatch pg_restore per run — custom dumps would be unrestorable
            return "backupCommandFormat is set to 'custom', which requires Craft 5.2+ — older versions lack the per-run restore-format control (pg_restore dispatch) Offsite needs.";
        }
        return "backupCommandFormat is set to '{$format}' — Offsite supports Craft's plain-SQL format and, on Craft 5.2+ (PostgreSQL), the pg_dump custom format.";
    }
}
