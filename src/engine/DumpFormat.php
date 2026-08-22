<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * The pg_dump output format of a backup. Plain is Craft's default SQL dump
 * (works on every DB driver); Custom is the pg_dump custom archive format
 * (PostgreSQL only, Craft 5.2+ via backupCommandFormat).
 */
enum DumpFormat: string
{
    case Plain = 'plain';
    case Custom = 'custom';

    /**
     * Maps Craft's resolved backup format value (backupCommandFormat /
     * Schema::getBackupFormat()) to a DumpFormat. Anything other than
     * 'custom' — including null — is the plain default.
     */
    public static function fromResolved(?string $resolved): self
    {
        return $resolved === 'custom' ? self::Custom : self::Plain;
    }

    /**
     * File extension for dumps of this format (mirrors Craft's
     * Connection::_getDumpExtension() mapping for the custom case).
     */
    public function extension(): string
    {
        return match ($this) {
            self::Custom => '.dump',
            self::Plain => '.sql',
        };
    }

    /**
     * Entry name used inside the backup zip archive.
     */
    public function entryName(): string
    {
        return 'backup' . $this->extension();
    }

    /**
     * Whether payloads of this format are already compressed: pg_dump custom
     * archives carry their own compression, so zip-level deflate would only
     * burn CPU without shrinking the archive.
     */
    public function preCompressed(): bool
    {
        return match ($this) {
            self::Custom => true,
            self::Plain => false,
        };
    }
}
