<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\SettingsValidator;
use PHPUnit\Framework\TestCase;

final class SettingsValidatorTest extends TestCase
{
    private function valid(): array
    {
        return [
            'bucket' => 'my-backups', 'region' => 'ap-northeast-1', 'endpoint' => '',
            'retentionMode' => 'plugin', 'retentionKeepCount' => 30,
            'heartbeatUrl' => 'https://hc-ping.com/uuid', 'slackWebhookUrl' => '',
        ];
    }

    public function testValidSettingsPass(): void
    {
        self::assertSame([], (new SettingsValidator())->validate($this->valid()));
    }

    public function testBucketRequired(): void
    {
        $s = $this->valid();
        $s['bucket'] = '';
        $errors = (new SettingsValidator())->validate($s);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('bucket', $errors[0]);
    }

    public function testHeartbeatMustBeHttps(): void
    {
        $s = $this->valid();
        $s['heartbeatUrl'] = 'ftp://bad';
        self::assertNotEmpty((new SettingsValidator())->validate($s));
    }

    public function testRetentionModeChecked(): void
    {
        $s = $this->valid();
        $s['retentionMode'] = 'magic';
        self::assertNotEmpty((new SettingsValidator())->validate($s));
    }

    public function testKeepCountPositive(): void
    {
        $s = $this->valid();
        $s['retentionKeepCount'] = 0;
        self::assertNotEmpty((new SettingsValidator())->validate($s));
    }

    public function testHttpEndpointRejectedByDefault(): void
    {
        $s = $this->valid();
        $s['endpoint'] = 'http://127.0.0.1:9000';
        $errors = (new SettingsValidator())->validate($s);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('https', $errors[0]);
    }

    public function testHttpEndpointAllowedWithExplicitOptIn(): void
    {
        $s = $this->valid();
        $s['endpoint'] = 'http://127.0.0.1:9000';
        $s['allowInsecureHttp'] = true;
        self::assertSame([], (new SettingsValidator())->validate($s));
    }

    public function testPartialCredentialsRejected(): void
    {
        $s = $this->valid();
        $s['accessKey'] = 'AKIA...';
        $s['secretKey'] = '';
        $errors = (new SettingsValidator())->validate($s);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('accessKey and secretKey', $errors[0]);
    }

    public function testMultipartThresholdLowerBound(): void
    {
        $s = $this->valid();
        $s['multipartThresholdMb'] = 1;
        self::assertNotEmpty((new SettingsValidator())->validate($s));
    }

    public function testWarningsWhenAllMonitoringChannelsAreEmpty(): void
    {
        $s = $this->valid();
        $s['heartbeatUrl'] = '';
        $s['slackWebhookUrl'] = '';
        $s['notifyEmail'] = '';
        $warnings = (new SettingsValidator())->warnings($s);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('heartbeat', $warnings[0]);
    }

    public function testHeartbeatIsTheOnlyMonitoringChannel(): void
    {
        $s = $this->valid();
        $s['slackWebhookUrl'] = '';
        $s['notifyEmail'] = '';
        $validator = new SettingsValidator();

        self::assertSame([], $validator->warnings($s));
        self::assertSame(['heartbeat'], $validator->monitoringChannels($s));
    }

    public function testAllMonitoringChannelsAreListed(): void
    {
        $s = $this->valid();
        $s['slackWebhookUrl'] = 'https://hooks.slack.com/services/example';
        $s['notifyEmail'] = 'backups@example.com';

        self::assertSame(
            ['slack webhook', 'email', 'heartbeat'],
            (new SettingsValidator())->monitoringChannels($s),
        );
    }

    public function testEmptyMonitoringChannelsDoNotAffectValidationErrors(): void
    {
        $s = $this->valid();
        $s['heartbeatUrl'] = '';
        $s['slackWebhookUrl'] = '';
        $s['notifyEmail'] = '';

        self::assertSame([], (new SettingsValidator())->validate($s));
    }
}
