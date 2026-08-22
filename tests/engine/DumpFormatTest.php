<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\DumpFormat;
use PHPUnit\Framework\TestCase;

final class DumpFormatTest extends TestCase
{
    public function testPlainMapsToSqlExtensionAndEntryName(): void
    {
        $fmt = DumpFormat::Plain;
        self::assertSame('.sql', $fmt->extension());
        self::assertSame('backup.sql', $fmt->entryName());
        self::assertFalse($fmt->preCompressed());
    }

    public function testCustomMapsToDumpExtensionAndEntryName(): void
    {
        $fmt = DumpFormat::Custom;
        self::assertSame('.dump', $fmt->extension());
        self::assertSame('backup.dump', $fmt->entryName());
        self::assertTrue($fmt->preCompressed());
    }

    public function testRoundTripsThroughBackingValue(): void
    {
        self::assertSame(DumpFormat::Plain, DumpFormat::from('plain'));
        self::assertSame(DumpFormat::Custom, DumpFormat::from('custom'));
        self::assertSame('custom', DumpFormat::Custom->value);
    }
}
