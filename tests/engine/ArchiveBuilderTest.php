<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\ArchiveBuilder;
use PHPUnit\Framework\TestCase;

final class ArchiveBuilderTest extends TestCase
{
    public function testZipsFileAndReportsSha256(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($src, "-- SQL DUMP\nSELECT 1;\n");
        $target = sys_get_temp_dir() . '/offsite-test-' . uniqid() . '.zip';

        $result = (new ArchiveBuilder())->zipFile($src, $target, 'backup.sql');

        self::assertFileExists($target);
        self::assertSame(hash_file('sha256', $target), $result->sha256);
        self::assertSame(filesize($target), $result->sizeBytes);

        $zip = new \ZipArchive();
        $zip->open($target);
        self::assertSame("-- SQL DUMP\nSELECT 1;\n", $zip->getFromName('backup.sql'));
        $zip->close();
        unlink($src);
        unlink($target);
    }

    public function testThrowsOnMissingSource(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ArchiveBuilder())->zipFile('/nonexistent/file.sql', sys_get_temp_dir() . '/x.zip', 'backup.sql');
    }
}
