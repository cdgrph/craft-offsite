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
    public function testSerializesOnlyCpFields(): void
    {
        $keys = array_keys((new Settings())->toArray());
        sort($keys);
        $expected = Settings::CP_FIELDS;
        sort($expected);
        self::assertSame($expected, $keys);
    }

    public function testSecretsNeverInSerializedOutput(): void
    {
        $s = new Settings();
        $s->accessKey = 'AKIAEXAMPLE';
        $s->secretKey = 'topsecret';
        $s->bucket = 'example-bucket';
        $s->slackWebhookUrl = 'https://hooks.example.com/services/x';
        $arr = $s->toArray();
        foreach (['accessKey', 'secretKey', 'bucket', 'endpoint', 'slackWebhookUrl', 'heartbeatUrl', 'notifyEmail', 'keyPrefix', 'region', 'allowInsecureHttp'] as $secret) {
            self::assertArrayNotHasKey($secret, $arr);
        }
    }

    public function testExplicitFieldRequestCannotExtractSecrets(): void
    {
        // Craft 5.9+ saves toArray(array_keys($post)); requested names resolve
        // as an intersection with fields(), so secret names must drop out.
        $s = new Settings();
        $s->secretKey = 'topsecret';
        self::assertSame(['retentionMode' => 'plugin'], $s->toArray(['secretKey', 'accessKey', 'retentionMode']));
    }

    public function testNumericStringsCoerceToIntInSerializedAndResolvedOutput(): void
    {
        $s = new Settings();
        $s->setAttributes(['retentionKeepCount' => '25', 'minFreeDiskMb' => '512', 'multipartThresholdMb' => '10'], false);
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
        $s = new Settings();
        $s->setAttributes(['notifyOnSuccess' => '1'], false);
        self::assertTrue($s->validate(), print_r($s->getErrors(), true));
        self::assertTrue($s->notifyOnSuccess);

        $s = new Settings();
        $s->setAttributes(['notifyOnSuccess' => ''], false);
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
            $model = new Settings();
            $model->setAttributes([$attr => $bad], false);
            self::assertFalse($model->validate(), "$attr=$bad should fail model rules");
            self::assertNotEmpty($validator->validate([$attr => $bad] + $base), "$attr=$bad should fail engine validator");

            $model = new Settings();
            $model->setAttributes([$attr => $good], false);
            self::assertTrue($model->validate(), "$attr=$good should pass model rules: " . print_r($model->getErrors(), true));
            self::assertSame([], $validator->validate([$attr => $good] + $base), "$attr=$good should pass engine validator");
        }

        $model = new Settings();
        $model->setAttributes(['retentionMode' => 'bogus'], false);
        self::assertFalse($model->validate());
        self::assertNotEmpty($validator->validate(['retentionMode' => 'bogus'] + $base));
    }
}
