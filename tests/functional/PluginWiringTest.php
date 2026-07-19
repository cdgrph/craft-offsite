<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\models\Settings;
use cdgrph\offsite\Plugin;
use craft\services\Config;
use craft\services\Utilities;
use craft\web\View;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

/**
 * Verifies the wiring between Plugin::createSettingsModel() and
 * Settings::setConfigFileKeys() — the seam that makes config/offsite.php
 * keys flow into validation skip and overriddenFields.
 *
 * Boots a minimal Yii console app (no DB, no network).
 */
final class PluginWiringTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/offsite-wiring-test-' . getmypid();
        @mkdir($this->configDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Event::off(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES);
        Event::off(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS);
        Plugin::setInstance(null);

        @unlink($this->configDir . '/offsite.php');
        @rmdir($this->configDir);
        \Yii::$app = null;
    }

    private function bootApp(): void
    {
        new class([
            'id' => 'offsite-wiring-test',
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

    private function writeConfigFile(array $config): void
    {
        $export = var_export($config, true);
        file_put_contents(
            $this->configDir . '/offsite.php',
            "<?php\nreturn {$export};\n",
        );
    }

    private function createPlugin(): Plugin
    {
        return new Plugin('offsite', \Yii::$app, [
            'basePath' => \dirname(__DIR__, 2) . '/src',
        ]);
    }

    public function testConfigFileKeysFlowIntoSettings(): void
    {
        $this->writeConfigFile([
            'bucket' => 'my-bucket',
            'secretKey' => 'raw-secret',
            'region' => 'us-east-1',
        ]);
        $this->bootApp();

        $plugin = $this->createPlugin();
        $settings = $plugin->getSettings();

        self::assertInstanceOf(Settings::class, $settings);
        $keys = $settings->getConfigFileKeys();
        sort($keys);
        self::assertSame(['bucket', 'region', 'secretKey'], $keys);
    }

    public function testEmptyConfigFileYieldsNoKeys(): void
    {
        // No offsite.php file at all
        $this->bootApp();

        $plugin = $this->createPlugin();
        $settings = $plugin->getSettings();

        self::assertInstanceOf(Settings::class, $settings);
        self::assertSame([], $settings->getConfigFileKeys());
    }

    public function testConfigFileKeysEnableValidationBypass(): void
    {
        $this->writeConfigFile([
            'bucket' => 'my-bucket',
            'secretKey' => 'raw-secret-value',
            'region' => 'ap-northeast-1',
        ]);
        $this->bootApp();

        $plugin = $this->createPlugin();
        $settings = $plugin->getSettings();

        // Config file values are injected into the model as attributes
        // by Craft's plugin machinery (setSettings). Simulate that here:
        $settings->setAttributes([
            'bucket' => 'my-bucket',
            'secretKey' => 'raw-secret-value',
            'region' => 'ap-northeast-1',
        ], false);

        // Without configFileKeys, raw values would fail the env-reference pattern.
        // With them, validation passes for config-file-overridden attributes.
        self::assertTrue(
            $settings->validate(),
            'Config-file keys should bypass env-reference validation: ' . print_r($settings->getErrors(), true),
        );
    }

    public function testConfigFileKeysFilteredToCpFieldsForOverrideDisplay(): void
    {
        $this->writeConfigFile([
            'bucket' => 'prod-bucket',
            'retentionMode' => 'plugin',
            'nonExistentField' => 'ignored',
        ]);
        $this->bootApp();

        $plugin = $this->createPlugin();
        $settings = $plugin->getSettings();

        // settingsHtml() passes array_intersect(CP_FIELDS, configFileKeys) as
        // overriddenFields to the template. Verify that only CP_FIELDS keys
        // from the config file are eligible for override display.
        $overridden = array_values(array_intersect(
            Settings::CP_FIELDS,
            $settings->getConfigFileKeys(),
        ));

        sort($overridden);
        self::assertSame(['bucket', 'retentionMode'], $overridden);
    }
}
