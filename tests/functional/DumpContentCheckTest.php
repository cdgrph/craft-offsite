<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\adapters\CraftDatabaseDumper;
use cdgrph\offsite\engine\DumpFormat;
use PHPUnit\Framework\TestCase;

/**
 * Content checks on dump files (shape + completeness), exercised against
 * fixtures that mirror real mysqldump / pg_dump plain output. Both checks are
 * pure file inspections, so no Craft app is booted.
 */
final class DumpContentCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/offsite-dump-check-test-' . getmypid();
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function write(string $bytes): string
    {
        $path = $this->dir . '/dump-' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($path, $bytes);
        return $path;
    }

    /** One mysqldump invocation, as Craft's default command runs it (twice: schema, then data) */
    private static function mysqldumpPart(string $body): string
    {
        return "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n"
            . "--\n-- Host: db    Database: db\n"
            . "-- ------------------------------------------------------\n"
            . "-- Server version\t8.0.36\n\n"
            . "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
            . "/*!40103 SET TIME_ZONE='+00:00' */;\n\n"
            . $body
            . "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n\n"
            . "-- Dump completed on 2026-10-26 12:00:00\n";
    }

    private static function mysqldumpTwoStep(): string
    {
        return self::mysqldumpPart("DROP TABLE IF EXISTS `craft_info`;\nCREATE TABLE `craft_info` (`id` int NOT NULL);\n")
            . self::mysqldumpPart("INSERT INTO `craft_info` VALUES (1);\n");
    }

    private static function pgDumpPlain(bool $unrestrict): string
    {
        return "--\n-- PostgreSQL database dump\n--\n\n"
            . ($unrestrict ? "\\restrict AbCdEf123\n\n" : '')
            . "-- Dumped from database version 16.10\n-- Dumped by pg_dump version 16.10\n\n"
            . "SET statement_timeout = 0;\nSET client_encoding = 'UTF8';\n\n"
            . "CREATE TABLE public.craft_info (id integer NOT NULL);\n\n"
            . "COPY public.craft_info (id) FROM stdin;\n1\n\\.\n\n"
            . "--\n-- PostgreSQL database dump complete\n--\n\n"
            . ($unrestrict ? "\\unrestrict AbCdEf123\n\n" : '');
    }

    private function assertRejected(callable $check, string $messageFragment): void
    {
        try {
            $check();
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    public function testMysqldumpTwoStepOutputPassesBothChecks(): void
    {
        $path = $this->write(self::mysqldumpTwoStep());
        CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain);
        CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }

    public function testPgDumpWithUnrestrictTrailerPassesBothChecks(): void
    {
        // pg_dump releases since August 2025 write a \unrestrict line after the marker
        $path = $this->write(self::pgDumpPlain(true));
        CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain);
        CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }

    public function testPgDumpWithoutUnrestrictTrailerPassesBothChecks(): void
    {
        $path = $this->write(self::pgDumpPlain(false));
        CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain);
        CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }

    public function testCrlfLineEndingsPass(): void
    {
        $path = $this->write(str_replace("\n", "\r\n", self::pgDumpPlain(true)));
        CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }

    public function testLargeDumpWithMarkerAtTheEndPasses(): void
    {
        $rows = str_repeat("INSERT INTO `craft_info` VALUES (1);\n", 2000);
        $path = $this->write(self::mysqldumpPart($rows));
        CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }

    public function testDumpCutShortIsRejected(): void
    {
        $full = self::pgDumpPlain(true);
        $path = $this->write(substr($full, 0, (int)strpos($full, 'COPY')));
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain),
            'not a complete plain SQL dump',
        );
    }

    public function testMysqlTwoStepDumpCutShortInTheDataPartIsRejected(): void
    {
        // The schema part's marker is still inside the tail window; only the
        // marker that ends the file proves the data part finished
        $schema = self::mysqldumpPart("CREATE TABLE `craft_info` (`id` int NOT NULL);\n");
        $data = self::mysqldumpPart("INSERT INTO `craft_info` VALUES (1);\n");
        $path = $this->write($schema . substr($data, 0, (int)strpos($data, 'INSERT') + 20));
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain),
            'not a complete plain SQL dump',
        );
    }

    public function testDumpWithoutCommentsIsRejectedWithGuidance(): void
    {
        // mysqldump --compact / --skip-comments: valid SQL, but completion cannot be verified
        $path = $this->write("/*!40101 SET NAMES utf8mb4 */;\nCREATE TABLE `craft_info` (`id` int NOT NULL);\nINSERT INTO `craft_info` VALUES (1);\n");
        CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain);
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain),
            '--skip-comments',
        );
    }

    public function testNextDumpHeaderAfterTheMarkerIsRejected(): void
    {
        // The data part stopped right after writing its comment header: only
        // the schema part's marker exists, followed by comment lines
        $schema = self::mysqldumpPart("CREATE TABLE `craft_info` (`id` int NOT NULL);\n");
        $header = "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n--\n-- Host: db    Database: db\n";
        $path = $this->write($schema . $header);
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain),
            'not a complete plain SQL dump',
        );
    }

    public function testSqlAfterTheMarkerIsRejected(): void
    {
        $path = $this->write(self::pgDumpPlain(false) . "CREATE TABLE public.extra (id integer);\n");
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Plain),
            'not a complete plain SQL dump',
        );
    }

    public function testCustomArchiveSkipsTheCompletionCheck(): void
    {
        $path = $this->write('PGDMP' . "\x00\x01\x02");
        CraftDatabaseDumper::assertDumpComplete($path, DumpFormat::Custom);
        $this->addToAssertionCount(1);
    }

    public function testCommentOnlyFileIsRejected(): void
    {
        // psql treats a comment-only file as a successful no-op restore
        $path = $this->write("--\n-- PostgreSQL database dump\n--\n\n-- Dumped from database version 16.10\n");
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain),
            'not a plain SQL dump',
        );
    }

    public function testStatementKeywordsInsideCommentsDoNotCount(): void
    {
        $path = $this->write("-- SET up by a wrapper script\n-- CREATE skipped\n--\n");
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain),
            'not a plain SQL dump',
        );
    }

    public function testCustomArchiveIsRejectedAsPlainEvenWithStatementLikeBytes(): void
    {
        // Custom archives carry SQL text (e.g. SET statements) inside the binary
        $path = $this->write('PGDMP' . "\x01\x0e\x00" . "\nSET statement_timeout = 0;\n");
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain),
            'not a plain SQL dump',
        );
    }

    public function testBlockCommentOnlyFileIsRejected(): void
    {
        // A plain block comment is not a statement, even when it mentions SET
        $path = $this->write("/* SET up by a wrapper script; CREATE nothing */\n-- Dump completed on 2026-10-26 12:00:00\n");
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain),
            'not a plain SQL dump',
        );
    }

    public function testUnclosedBlockCommentIsRejected(): void
    {
        $path = $this->write("--\n/* wrapper notes: SET and CREATE come later\n");
        $this->assertRejected(
            fn() => CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain),
            'not a plain SQL dump',
        );
    }

    public function testCommentOpenerInsideDataDoesNotHideStatements(): void
    {
        // Entry content routinely carries "/*" (inline CSS/JS) with no closing
        // "*/" nearby; a large dump of such rows must still pass
        $rows = str_repeat("INSERT INTO `craft_content` VALUES (1,'<style>/* hero',NULL);\n", 30000);
        $path = $this->write(self::mysqldumpPart($rows));
        CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }

    public function testExecutableCommentsCountAsStatements(): void
    {
        // MySQL /*! ... */ and MariaDB /*M! ... */ comments are executed
        foreach (["/*!40101 SET NAMES utf8mb4 */;\n", "/*M!100100 SET NAMES utf8mb4 */;\n"] as $bytes) {
            CraftDatabaseDumper::assertDumpLooksLike($this->write($bytes), DumpFormat::Plain);
        }
        $this->addToAssertionCount(1);
    }

    public function testLongCommentHeaderBeforeTheFirstStatementPasses(): void
    {
        // The first statement may sit well past the first few KiB behind a long header
        $header = str_repeat("-- generated by a wrapper script, line padding padding padding\n", 200);
        $path = $this->write($header . self::pgDumpPlain(true));
        CraftDatabaseDumper::assertDumpLooksLike($path, DumpFormat::Plain);
        $this->addToAssertionCount(1);
    }
}
