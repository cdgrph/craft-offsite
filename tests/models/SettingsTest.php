<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\models;

use cdgrph\offsite\engine\SettingsValidator;
use cdgrph\offsite\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Guards the SECURITY.md promise that secrets are never persisted to
 * project config: Craft saves plugin settings from toArray(), so the
 * serialized field set IS the persistence boundary.
 */
final class SettingsTest extends TestCase
{
    private static function withConnection(array $attributes = []): Settings
    {
        $settings = new Settings();
        $settings->setAttributes($attributes + [
            'bucket' => '$OFFSITE_BUCKET',
            'region' => '$OFFSITE_REGION',
        ], false);
        return $settings;
    }

    public function testSerializesOnlyCpFields(): void
    {
        $keys = array_keys((new Settings())->toArray());
        sort($keys);
        $expected = Settings::CP_FIELDS;
        sort($expected);
        self::assertSame($expected, $keys);
    }

    public function testRawValuesNeverSerialized(): void
    {
        $s = new Settings();
        $rawValues = [
            'endpoint' => 'https://s3.example.com',
            'region' => 'us-east-1',
            'bucket' => 'example-bucket',
            'keyPrefix' => 'backups',
            'accessKey' => 'AKIAEXAMPLE',
            'secretKey' => 'topsecret',
            'slackWebhookUrl' => 'https://hooks.example.com/services/x',
            'notifyEmail' => 'ops@example.com',
            'heartbeatUrl' => 'https://heartbeat.example.com/x',
        ];
        $s->setAttributes($rawValues, false);
        $arr = $s->toArray();

        foreach (Settings::ENV_REFERENCE_FIELDS as $attribute) {
            self::assertSame('', $arr[$attribute]);
        }
    }

    public function testEnvReferencesSerializeVerbatim(): void
    {
        $references = [
            'endpoint' => '$OFFSITE_ENDPOINT',
            'region' => '$OFFSITE_REGION',
            'bucket' => '$OFFSITE_BUCKET',
            'keyPrefix' => '$OFFSITE_KEY_PREFIX',
            'accessKey' => '$OFFSITE_ACCESS_KEY',
            'secretKey' => '$OFFSITE_SECRET_KEY',
            'slackWebhookUrl' => '$OFFSITE_SLACK_WEBHOOK_URL',
            'notifyEmail' => '$OFFSITE_NOTIFY_EMAIL',
            'heartbeatUrl' => '$OFFSITE_HEARTBEAT_URL',
        ];
        $s = new Settings();
        $s->setAttributes($references, false);

        foreach ($references as $attribute => $reference) {
            self::assertSame($reference, $s->toArray()[$attribute]);
        }
    }

    public function testCpDisplayValueMasksRawValues(): void
    {
        $s = new Settings();
        $s->secretKey = 'raw-from-config-file';
        self::assertSame('', $s->cpDisplayValue('secretKey'));

        $s->secretKey = '$OFFSITE_SECRET_KEY' . "\n";
        self::assertSame('', $s->cpDisplayValue('secretKey'));

        $s->secretKey = '$OFFSITE_SECRET_KEY';
        self::assertSame('$OFFSITE_SECRET_KEY', $s->cpDisplayValue('secretKey'));
    }

    public function testRawValuesFailValidation(): void
    {
        $rawValues = [
            'endpoint' => 'https://s3.example.com',
            'region' => 'us-east-1',
            'bucket' => 'example-bucket',
            'keyPrefix' => 'backups',
            'accessKey' => 'AKIAEXAMPLE',
            'secretKey' => 'topsecret',
            'slackWebhookUrl' => 'https://hooks.example.com/services/x',
            'notifyEmail' => 'ops@example.com',
            'heartbeatUrl' => 'https://heartbeat.example.com/x',
        ];

        foreach ($rawValues as $attribute => $rawValue) {
            $s = new Settings();
            $s->setAttributes([$attribute => $rawValue], false);
            self::assertFalse($s->validate(), "$attribute should reject raw values");
            self::assertNotEmpty($s->getErrors($attribute));
        }

        foreach (['$OFFSITE_X' . "\n", '$', '${OFFSITE_X}'] as $invalidReference) {
            $s = new Settings();
            $s->bucket = $invalidReference;
            self::assertFalse($s->validate(), 'Invalid environment variable references should fail validation');
            self::assertNotEmpty($s->getErrors('bucket'));
        }
    }

    public function testEnvReferencesAndEmptyPassValidation(): void
    {
        $references = [
            'endpoint' => '$OFFSITE_ENDPOINT',
            'region' => '$OFFSITE_REGION',
            'bucket' => '$OFFSITE_BUCKET',
            'keyPrefix' => '$OFFSITE_KEY_PREFIX',
            'accessKey' => '$OFFSITE_ACCESS_KEY',
            'secretKey' => '$OFFSITE_SECRET_KEY',
            'slackWebhookUrl' => '$OFFSITE_SLACK_WEBHOOK_URL',
            'notifyEmail' => '$OFFSITE_NOTIFY_EMAIL',
            'heartbeatUrl' => '$OFFSITE_HEARTBEAT_URL',
        ];
        $s = new Settings();
        $s->setAttributes($references, false);
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));

        $s = self::withConnection();
        $s->secretKey = '$offsite_secret';
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));

        $optionalFields = \array_diff(Settings::ENV_REFERENCE_FIELDS, ['bucket', 'region']);
        $s = self::withConnection(\array_fill_keys($optionalFields, ''));
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));
    }

    public function testConfigFileKeysSkipReferenceValidation(): void
    {
        $s = self::withConnection();
        $s->setConfigFileKeys(['secretKey']);
        $s->secretKey = 'raw-from-config-file';

        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));
        self::assertSame([], $s->getErrors('secretKey'));
        self::assertSame('', $s->toArray()['secretKey']);
    }

    public function testBucketRequired(): void
    {
        $s = self::withConnection(['bucket' => '']);
        self::assertFalse($s->validate());
        self::assertNotEmpty($s->getErrors('bucket'));

        $s = self::withConnection(['bucket' => '']);
        $s->setConfigFileKeys(['bucket']);
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));
    }

    public function testRegionRequiredOnlyWithoutEndpoint(): void
    {
        $s = self::withConnection(['endpoint' => '', 'region' => '']);
        self::assertFalse($s->validate());
        self::assertNotEmpty($s->getErrors('region'));

        $s = self::withConnection(['endpoint' => '$OFFSITE_ENDPOINT', 'region' => '']);
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));

        $s = self::withConnection(['endpoint' => '', 'region' => '']);
        $s->setConfigFileKeys(['region']);
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));

        $s = self::withConnection(['endpoint' => 'https://s3.example.com', 'region' => '']);
        $s->setConfigFileKeys(['endpoint']);
        self::assertTrue($s->validate(), \print_r($s->getErrors(), true));

        // An endpoint override alone must not exempt region — only a non-empty
        // endpoint value or region's own override does.
        $s = self::withConnection(['endpoint' => '', 'region' => '']);
        $s->setConfigFileKeys(['endpoint']);
        self::assertFalse($s->validate());
        self::assertNotEmpty($s->getErrors('region'));
    }

    public function testExplicitFieldRequestCannotExtractSecrets(): void
    {
        // Craft 5.9+ saves toArray(array_keys($post)); requested names resolve
        // as an intersection with fields(), while serialized environment fields
        // still pass through the raw-value guard.
        $s = new Settings();
        $s->secretKey = 'topsecret';
        self::assertSame(['retentionMode' => 'plugin'], $s->toArray(['allowInsecureHttp', 'retentionMode']));
        self::assertSame(['secretKey' => ''], $s->toArray(['secretKey']));
    }

    public function testNumericStringsCoerceToIntInSerializedAndResolvedOutput(): void
    {
        $s = self::withConnection(['retentionKeepCount' => '25', 'minFreeDiskMb' => '512', 'multipartThresholdMb' => '10']);
        self::assertTrue($s->validate(), print_r($s->getErrors(), true));
        self::assertSame(25, $s->toArray()['retentionKeepCount']);
        self::assertSame(512, $s->resolved()['minFreeDiskMb']);
        self::assertSame(10, $s->resolved()['multipartThresholdMb']);
    }

    public function testNonNumericInputFailsValidationInsteadOfFataling(): void
    {
        foreach (['retentionKeepCount', 'minFreeDiskMb', 'multipartThresholdMb'] as $attr) {
            $s = new Settings();
            $s->setAttributes([$attr => 'abc'], false);
            self::assertFalse($s->validate(), "$attr=abc should fail validation");
            self::assertNotEmpty($s->getErrors($attr));
        }
    }

    public function testEmptyStringFailsRequired(): void
    {
        foreach (['retentionKeepCount', 'minFreeDiskMb', 'multipartThresholdMb'] as $attr) {
            $s = new Settings();
            $s->setAttributes([$attr => ''], false);
            self::assertFalse($s->validate(), "$attr='' should fail validation");
            self::assertNotEmpty($s->getErrors($attr));
        }
    }

    public function testLightswitchPostValuesCoerceSafely(): void
    {
        $s = self::withConnection(['notifyOnSuccess' => '1']);
        self::assertTrue($s->validate(), print_r($s->getErrors(), true));
        self::assertTrue($s->notifyOnSuccess);

        $s = self::withConnection(['notifyOnSuccess' => '']);
        self::assertTrue($s->validate(), print_r($s->getErrors(), true));
        self::assertFalse($s->notifyOnSuccess);
    }

    public function testInvalidRetentionModeRejected(): void
    {
        $s = new Settings();
        $s->setAttributes(['retentionMode' => 'delete-everything'], false);
        self::assertFalse($s->validate());
        self::assertNotEmpty($s->getErrors('retentionMode'));
    }

    /**
     * CP rules and the engine-side SettingsValidator share constants; this
     * parity check fails if a boundary drifts in only one of the two.
     */
    public function testBoundaryParityWithSettingsValidator(): void
    {
        $validator = new SettingsValidator();
        $base = [
            'bucket' => 'example-bucket', 'region' => 'us-east-1', 'endpoint' => '',
            'retentionMode' => 'plugin', 'retentionKeepCount' => 30,
        ];

        $cases = [
            ['retentionKeepCount', SettingsValidator::MIN_KEEP_COUNT - 1, SettingsValidator::MIN_KEEP_COUNT],
            ['multipartThresholdMb', SettingsValidator::MIN_MULTIPART_MB - 1, SettingsValidator::MIN_MULTIPART_MB],
            ['minFreeDiskMb', SettingsValidator::MIN_FREE_DISK_MB - 1, SettingsValidator::MIN_FREE_DISK_MB],
        ];
        foreach ($cases as [$attr, $bad, $good]) {
            $model = self::withConnection([$attr => $bad]);
            self::assertFalse($model->validate(), "$attr=$bad should fail model rules");
            self::assertNotEmpty($validator->validate([$attr => $bad] + $base), "$attr=$bad should fail engine validator");

            $model = self::withConnection([$attr => $good]);
            self::assertTrue($model->validate(), "$attr=$good should pass model rules: " . print_r($model->getErrors(), true));
            self::assertSame([], $validator->validate([$attr => $good] + $base), "$attr=$good should pass engine validator");
        }

        $model = self::withConnection(['bucket' => '']);
        self::assertFalse($model->validate(), 'Empty bucket should fail model rules');
        self::assertNotEmpty($validator->validate(['bucket' => ''] + $base), 'Empty bucket should fail engine validator');

        $model = self::withConnection(['endpoint' => '', 'region' => '']);
        self::assertFalse($model->validate(), 'Empty region without an endpoint should fail model rules');
        self::assertNotEmpty($validator->validate(['region' => ''] + $base), 'Empty region without an endpoint should fail engine validator');

        $model = self::withConnection(['endpoint' => '$OFFSITE_ENDPOINT', 'region' => '']);
        self::assertTrue($model->validate(), 'Empty region with an endpoint should pass model rules: ' . print_r($model->getErrors(), true));
        self::assertSame([], $validator->validate(['region' => '', 'endpoint' => 'https://s3.example.com'] + $base), 'Empty region with an endpoint should pass engine validator');

        $model = self::withConnection(['retentionMode' => 'bogus']);
        self::assertFalse($model->validate());
        self::assertNotEmpty($validator->validate(['retentionMode' => 'bogus'] + $base));
    }
}
