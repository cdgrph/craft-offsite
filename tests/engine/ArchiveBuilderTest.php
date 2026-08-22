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

    public function testStoreModeLeavesEntryUncompressed(): void
    {
        // Incompressible payload, so deflate could never match the stored size
        $src = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($src, random_bytes(4096));
        $target = sys_get_temp_dir() . '/offsite-test-' . uniqid() . '.zip';

        (new ArchiveBuilder())->zipFile($src, $target, 'backup.dump', compress: false);

        $zip = new \ZipArchive();
        $zip->open($target);
        $stat = $zip->statName('backup.dump');
        self::assertSame($stat['size'], $stat['comp_size'], 'stored entry must not be compressed');
        self::assertSame(file_get_contents($src), $zip->getFromName('backup.dump'));
        $zip->close();
        unlink($src);
        unlink($target);
    }

    public function testDefaultModeDeflates(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($src, str_repeat("-- fake dump\nSELECT 1;\n", 5000));
        $target = sys_get_temp_dir() . '/offsite-test-' . uniqid() . '.zip';

        (new ArchiveBuilder())->zipFile($src, $target, 'backup.sql');

        $zip = new \ZipArchive();
        $zip->open($target);
        $stat = $zip->statName('backup.sql');
        self::assertLessThan($stat['size'], $stat['comp_size'], 'deflate must shrink a compressible dump');
        $zip->close();
        unlink($src);
        unlink($target);
    }
}
