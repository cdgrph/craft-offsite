<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\adapters\CraftDatabaseDumper;
use cdgrph\offsite\engine\DumpFormat;
use cdgrph\offsite\tests\support\FakeDbConnection;
use craft\services\Config;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CraftDatabaseDumper's backupCommandFormat handling against a real
 * craft\services\Config reading general.php from a temp config dir, with the
 * database faked out (FakeDbConnection) so dump() runs end to end without a
 * real DB.
 *
 * Hermetic: each case boots a fresh app, because Config caches resolved
 * settings per instance. CRAFT_BACKUP_COMMAND_FORMAT is cleared in setUp and
 * restored in tearDown, because Craft applies CRAFT_* env overrides on top of
 * file config — a stray value would contaminate every case.
 */
final class BackupCommandFormatTest extends TestCase
{
    /** Smallest plain dumps that pass both content checks (statement + the driver's footer) */
    private const PLAIN_DUMP = "-- fake dump\nSET x = 1;\n-- Dump completed on 2026-10-26 12:00:00\n";
    private const PG_PLAIN_DUMP = "-- fake dump\nSET x = 1;\n--\n-- PostgreSQL database dump complete\n--\n";

    private string $configDir;
    private string $workDir;
    private mixed $savedServerEnv = null;
    private string|false $savedProcessEnv = false;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/offsite-backup-format-test-' . getmypid();
        $this->configDir = $base . '-config';
        $this->workDir = $base . '-work';
        @mkdir($this->configDir, 0755, true);
        @mkdir($this->workDir, 0755, true);

        $this->savedServerEnv = $_SERVER['CRAFT_BACKUP_COMMAND_FORMAT'] ?? null;
        $this->savedProcessEnv = getenv('CRAFT_BACKUP_COMMAND_FORMAT');
        unset($_SERVER['CRAFT_BACKUP_COMMAND_FORMAT']);
        putenv('CRAFT_BACKUP_COMMAND_FORMAT');
    }

    protected function tearDown(): void
    {
        if ($this->savedServerEnv === null) {
            unset($_SERVER['CRAFT_BACKUP_COMMAND_FORMAT']);
        } else {
            $_SERVER['CRAFT_BACKUP_COMMAND_FORMAT'] = $this->savedServerEnv;
        }
        if ($this->savedProcessEnv === false) {
            putenv('CRAFT_BACKUP_COMMAND_FORMAT');
        } else {
            putenv('CRAFT_BACKUP_COMMAND_FORMAT=' . $this->savedProcessEnv);
        }
        @unlink($this->configDir . '/general.php');
        @rmdir($this->configDir);
        foreach (glob($this->workDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->workDir);
        \Yii::$app = null;
    }

    /**
     * Schema stub mirroring the Craft 5.2+ format plumbing: setBackupFormat()
     * stores a per-run override, getBackupFormat() resolves override ?? config
     * exactly like craft\db\pgsql\Schema does.
     */
    private function schemaWithFormatSupport(): object
    {
        return new class
        {
            private ?string $backupFormat = null;

            public function setBackupFormat(string $format): void
            {
                $this->backupFormat = $format;
            }

            public function getBackupFormat(): ?string
            {
                return $this->backupFormat
                    ?? \Craft::$app->getConfig()->getGeneral()->backupCommandFormat;
            }
        };
    }

    /** @param string $phpReturn PHP expression used as the general.php return value */
    private function bootApp(string $phpReturn, FakeDbConnection $db): void
    {
        file_put_contents(
            $this->configDir . '/general.php',
            "<?php\nreturn {$phpReturn};\n",
        );
        new class([
            'id' => 'offsite-backup-format-test',
            'basePath' => $this->configDir,
            'components' => [
                'config' => [
                    'class' => Config::class,
                    'configDir' => $this->configDir,
                ],
            ],
        ], $db) extends \yii\console\Application {
            public function __construct(array $config, private readonly FakeDbConnection $db)
            {
                parent::__construct($config);
            }

            public function getConfig(): Config
            {
                return $this->get('config');
            }

            public function getDb(): FakeDbConnection
            {
                return $this->db;
            }
        };
    }

    public function testCustomFormatOnCraft52ProducesCustomDumpResult(): void
    {
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), 'PGDMP' . "\x00\x01\x02");
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);

        $result = (new CraftDatabaseDumper())->dump($this->workDir);

        self::assertSame(DumpFormat::Custom, $result->format);
        self::assertSame('dump', pathinfo($result->path, PATHINFO_EXTENSION));
        self::assertCount(1, $db->backupCalls);
        self::assertFileExists($result->path);
    }

    public function testCustomFormatOnPreCraft52IsRejectedBeforeDump(): void
    {
        // Pre-5.2 Craft lacks Schema::setRestoreFormat(), so Offsite cannot
        // dispatch pg_restore per run — custom dumps would be unrestorable
        $db = new FakeDbConnection(true, new \stdClass(), 'should never be written');
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);

        try {
            (new CraftDatabaseDumper())->dump($this->workDir);
            self::fail('expected RuntimeException for custom on pre-5.2 Craft');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Craft 5.2', $e->getMessage());
        }
        self::assertSame([], $db->backupCalls, 'rejection must happen before any dump work');
    }

    /** @return array<string, array{string}> */
    public static function unsupportedFormats(): array
    {
        return [
            'tar' => ['tar'],
            'directory' => ['directory'],
            'unknown value' => ['futureFormat'],
        ];
    }

    /** @dataProvider unsupportedFormats */
    public function testUnsupportedFormatsAreRejectedBeforeDump(string $format): void
    {
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), 'should never be written');
        $this->bootApp("['backupCommandFormat' => '{$format}']", $db);

        try {
            (new CraftDatabaseDumper())->dump($this->workDir);
            self::fail("expected RuntimeException for format '{$format}'");
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($format, $e->getMessage());
        }
        self::assertSame([], $db->backupCalls);
    }

    public function testPgsqlDefaultNullDumpsPlain(): void
    {
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), self::PG_PLAIN_DUMP);
        $this->bootApp('[]', $db);

        $result = (new CraftDatabaseDumper())->dump($this->workDir);

        self::assertSame(DumpFormat::Plain, $result->format);
        self::assertSame('sql', pathinfo($result->path, PATHINFO_EXTENSION));
    }

    public function testPlainDumpWithoutCompletionMarkerIsRejectedAndRemoved(): void
    {
        // A dump cut short (or produced without comments) must never be committed
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), "-- fake dump\nSET x = 1;\n");
        $this->bootApp('[]', $db);

        try {
            (new CraftDatabaseDumper())->dump($this->workDir);
            self::fail('expected RuntimeException for a dump without the completion marker');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not a complete plain SQL dump', $e->getMessage());
        }
        self::assertSame([], glob($this->workDir . '/dump-*') ?: [], 'a rejected dump must not stay in the work dir');
    }

    public function testMysqlIgnoresBackupCommandFormat(): void
    {
        // backupCommandFormat is a PostgreSQL-only Craft setting (no-op on MySQL)
        $db = new FakeDbConnection(false, null, self::PLAIN_DUMP);
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);

        $result = (new CraftDatabaseDumper())->dump($this->workDir);

        self::assertSame(DumpFormat::Plain, $result->format);
        self::assertSame('sql', pathinfo($result->path, PATHINFO_EXTENSION));
    }

    public function testCustomExpectationWithPlainOutputIsRejected(): void
    {
        // Second line of defense: config says custom but the produced file is plain
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), "-- fake dump\nSET x = 1;\n");
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);

        $this->expectException(\RuntimeException::class);
        try {
            (new CraftDatabaseDumper())->dump($this->workDir);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('PGDMP', $e->getMessage());
            throw $e;
        }
    }

    public function testPlainExpectationWithCustomOutputIsRejected(): void
    {
        // Second line of defense: a custom archive must never slip through as SQL
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), 'PGDMP' . "\x00\x01\x02");
        $this->bootApp('[]', $db);

        $this->expectException(\RuntimeException::class);
        (new CraftDatabaseDumper())->dump($this->workDir);
    }

    public function testResolvedBackupFormatReadsConfigForPgsql(): void
    {
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), '');
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);
        self::assertSame('custom', CraftDatabaseDumper::resolvedBackupFormat());

        $this->bootApp('[]', $db);
        self::assertNull(CraftDatabaseDumper::resolvedBackupFormat());
    }

    public function testResolvedBackupFormatIsNullOnMysql(): void
    {
        $db = new FakeDbConnection(false, null, '');
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);
        self::assertNull(CraftDatabaseDumper::resolvedBackupFormat());
    }

    public function testResolvedBackupFormatPrefersSchemaRuntimeOverride(): void
    {
        // Another actor in the same process may call setBackupFormat(); the
        // plugin's expectation must track what pg_dump actually receives
        $schema = $this->schemaWithFormatSupport();
        $schema->setBackupFormat('custom');
        $db = new FakeDbConnection(true, $schema, '');
        $this->bootApp('[]', $db); // config says plain, runtime override says custom

        self::assertSame('custom', CraftDatabaseDumper::resolvedBackupFormat());
    }

    public function testFailedDumpLeavesNoPartialFileBehind(): void
    {
        $db = new FakeDbConnection(true, $this->schemaWithFormatSupport(), '');
        $db->failBackupTo = true;
        $this->bootApp("['backupCommandFormat' => 'custom']", $db);

        try {
            (new CraftDatabaseDumper())->dump($this->workDir);
            self::fail('expected RuntimeException from failed backupTo');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('pg_dump exited 1', $e->getMessage());
        }
        self::assertSame([], glob($this->workDir . '/dump-*') ?: [], 'a failed dump must not leave a partial file in the work dir');
    }

    public function testDetectDumpFormatSniffsFileContent(): void
    {
        $custom = $this->workDir . '/probe.dump';
        file_put_contents($custom, 'PGDMP' . "\x00\x01");
        self::assertSame(DumpFormat::Custom, CraftDatabaseDumper::detectDumpFormat($custom));

        $plain = $this->workDir . '/probe.sql';
        file_put_contents($plain, "-- fake dump\nSET x = 1;\n");
        self::assertSame(DumpFormat::Plain, CraftDatabaseDumper::detectDumpFormat($plain));
    }
}
