<?php
declare(strict_types=1);

namespace cdgrph\offsite\adapters;

use cdgrph\offsite\engine\DatabaseDumper;

/**
 * Delegates to Craft core's native backup (same mysqldump/pg_dump handling
 * and requirements as the CP's own Database Backup utility — spec §1).
 */
final class CraftDatabaseDumper implements DatabaseDumper
{
    public static function unsupportedBackupFormat(): ?string
    {
        // Never commit anything but plain SQL (backupCommandFormat=custom/tar/directory):
        // if this went unnoticed until restore time, unrestorable backups would pile up
        // as generations while retention deletes the older, restorable ones (Codex
        // review R7). Reject at the settings level instead of relying on heuristics
        // (Codex review F4)
        $general = \Craft::$app->getConfig()->getGeneral();
        $format = property_exists($general, 'backupCommandFormat') ? $general->backupCommandFormat : null;
        // backupCommandFormat is a PostgreSQL-only setting (a no-op on MySQL), so it
        // is only checked for pgsql
        if (\Craft::$app->getDb()->getIsPgsql() && $format !== null && $format !== 'plain') {
            return $format;
        }

        return null;
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

    public function dump(string $targetDir): string
    {
        $format = self::unsupportedBackupFormat();
        if ($format !== null) {
            throw new \RuntimeException(
                "backupCommandFormat is set to '{$format}' — Offsite v1.0 supports only Craft's plain-SQL backup format."
            );
        }

        $path = rtrim($targetDir, '/') . '/dump-' . date('YmdHis') . '.sql';
        \Craft::$app->getDb()->backupTo($path);
        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException("Craft DB backup produced no file at {$path}.");
        }
        $head = (string)file_get_contents($path, false, null, 0, 512);
        // A pg_dump custom archive contains strings like SET inside its binary and can
        // slip past the heuristic, so the PGDMP magic is rejected explicitly (Codex review F4)
        $notSql = str_starts_with($head, 'PGDMP')
            || $head === ''
            || (!str_contains($head, '--') && stripos($head, 'SET') === false && stripos($head, 'CREATE') === false);
        if ($notSql) {
            @unlink($path);
            throw new \RuntimeException(
                'Backup output is not a plain SQL dump. Offsite v1.0 requires Craft\'s default plain-SQL backup format (check backupCommandFormat).'
            );
        }
        return $path;
    }
}
