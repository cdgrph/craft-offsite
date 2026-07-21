<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\adapters\CraftDatabaseDumper;
use craft\services\Config;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CraftDatabaseDumper::backupCommandDisabled() against a real
 * craft\services\Config reading general.php from a temp config dir.
 *
 * Hermetic: each case boots a fresh app, because Config caches resolved
 * settings per instance. CRAFT_BACKUP_COMMAND is cleared in setUp and
 * restored in tearDown, because Craft applies CRAFT_* env overrides on
 * top of file config — a stray value would contaminate every case.
 *
 * Covers the helper's decision logic only. The diagnose wiring (message,
 * position, exit code) is verified via the CLI smoke run and the Cloud
 * sandbox check described in the design doc.
 */
final class BackupCommandDisabledTest extends TestCase
{
    private string $configDir;
    private mixed $savedServerEnv = null;
    private string|false $savedProcessEnv = false;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/offsite-backup-command-test-' . getmypid();
        @mkdir($this->configDir, 0755, true);

        $this->savedServerEnv = $_SERVER['CRAFT_BACKUP_COMMAND'] ?? null;
        $this->savedProcessEnv = getenv('CRAFT_BACKUP_COMMAND');
        unset($_SERVER['CRAFT_BACKUP_COMMAND']);
        putenv('CRAFT_BACKUP_COMMAND');
    }

    protected function tearDown(): void
    {
        if ($this->savedServerEnv === null) {
            unset($_SERVER['CRAFT_BACKUP_COMMAND']);
        } else {
            $_SERVER['CRAFT_BACKUP_COMMAND'] = $this->savedServerEnv;
        }
        if ($this->savedProcessEnv === false) {
            putenv('CRAFT_BACKUP_COMMAND');
        } else {
            putenv('CRAFT_BACKUP_COMMAND=' . $this->savedProcessEnv);
        }
        @unlink($this->configDir . '/general.php');
        @rmdir($this->configDir);
        \Yii::$app = null;
    }

    /** @param string $phpReturn PHP expression used as the general.php return value */
    private function bootAppWithGeneralConfig(string $phpReturn): void
    {
        file_put_contents(
            $this->configDir . '/general.php',
            "<?php\nreturn {$phpReturn};\n",
        );
        new class([
            'id' => 'offsite-backup-command-test',
            'basePath' => $this->configDir,
            'components' => [
                'config' => [
                    'class' => Config::class,
                    'configDir' => $this->configDir,
                ],
            ],
        ]) extends \yii\console\Application {
            public function getConfig(): Config
            {
                return $this->get('config');
            }
        };
    }

    public function testFalseIsDisabled(): void
    {
        $this->bootAppWithGeneralConfig("['backupCommand' => false]");
        self::assertTrue(CraftDatabaseDumper::backupCommandDisabled());
    }

    public function testDefaultNullIsNotDisabled(): void
    {
        $this->bootAppWithGeneralConfig('[]');
        self::assertFalse(CraftDatabaseDumper::backupCommandDisabled());
    }

    public function testCustomCommandStringIsNotDisabled(): void
    {
        $this->bootAppWithGeneralConfig("['backupCommand' => '/usr/bin/mysqldump --single-transaction']");
        self::assertFalse(CraftDatabaseDumper::backupCommandDisabled());
    }

    public function testClosureIsNotDisabled(): void
    {
        $this->bootAppWithGeneralConfig("['backupCommand' => fn(): string => '/usr/bin/mysqldump']");
        self::assertFalse(CraftDatabaseDumper::backupCommandDisabled());
    }

    public function testEnvOverrideFalseWinsOverFileConfig(): void
    {
        $_SERVER['CRAFT_BACKUP_COMMAND'] = 'false';
        putenv('CRAFT_BACKUP_COMMAND=false');
        $this->bootAppWithGeneralConfig("['backupCommand' => '/usr/bin/mysqldump']");
        self::assertTrue(CraftDatabaseDumper::backupCommandDisabled());
    }
}
