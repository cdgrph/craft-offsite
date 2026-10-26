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
    /** Bytes read from the start of a dump for the shape check */
    private const HEAD_BYTES = 4096;

    /** Bytes read from the end of a dump for the completion check */
    private const TAIL_BYTES = 4096;

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
            self::assertDumpComplete($path, $fmt);
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
        $head = (string)file_get_contents($path, false, null, 0, self::HEAD_BYTES);
        if ($format === DumpFormat::Custom) {
            if (!str_starts_with($head, 'PGDMP')) {
                throw new \RuntimeException('Dump output is not a pg_dump custom archive (missing PGDMP magic). Check backupCommandFormat / backupCommand.');
            }
            return;
        }
        // Comment lines alone never qualify: psql runs a comment-only file as a
        // successful no-op, which would let an empty rollback point "restore".
        // A pg_dump custom archive contains strings like SET inside its binary and
        // can slip past the statement match, so the PGDMP magic is rejected explicitly
        $statements = (string)preg_replace('/^[ \t]*--.*$/m', '', $head);
        $notSql = str_starts_with($head, 'PGDMP')
            || !preg_match('/\b(?:SET|CREATE|INSERT|DROP)\b/i', $statements);
        if ($notSql) {
            throw new \RuntimeException('Backup output is not a plain SQL dump. Check backupCommandFormat / backupCommand.');
        }
    }

    /**
     * Verifies that a plain SQL dump ran to completion: mysqldump/mariadb-dump
     * end with "-- Dump completed", pg_dump with "-- PostgreSQL database dump
     * complete". Only the last marker counts, and nothing but blank lines,
     * comments and pg_dump's \unrestrict line may follow it — Craft's MySQL
     * command appends a second mysqldump run, so an earlier marker only proves
     * the schema part finished. Custom archives are not covered.
     *
     * Applies to dumps produced in this environment (backup run, pre-restore
     * rollback point), not to downloaded backups: the catalog's SHA-256 pins
     * those to the file their backup run uploaded, and requiring the marker
     * there would refuse older backups taken without it.
     */
    public static function assertDumpComplete(string $path, DumpFormat $format): void
    {
        if ($format === DumpFormat::Custom) {
            return;
        }
        $size = (int)filesize($path);
        $tail = (string)file_get_contents($path, false, null, max(0, $size - self::TAIL_BYTES));
        $complete = false;
        if (preg_match_all('/^-- (?:Dump completed|PostgreSQL database dump complete)\b.*$/m', $tail, $m, PREG_OFFSET_CAPTURE)) {
            [$marker, $offset] = end($m[0]);
            $complete = true;
            foreach (preg_split('/\R/', substr($tail, $offset + strlen($marker))) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '--') && !str_starts_with($line, '\\unrestrict ')) {
                    $complete = false;
                    break;
                }
            }
        }
        if (!$complete) {
            throw new \RuntimeException('Backup output is not a complete plain SQL dump: the completion marker that mysqldump and pg_dump write at the end is missing. The dump may have been cut short, or a backupCommand override strips comments (--skip-comments / --compact) — keep comments so the dump can be verified.');
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
