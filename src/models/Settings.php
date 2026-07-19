<?php
declare(strict_types=1);

namespace cdgrph\offsite\models;

use cdgrph\offsite\engine\SettingsValidator;
use craft\base\Model;
use craft\helpers\App;

/**
 * Hybrid settings: the operational settings in CP_FIELDS are
 * editable in the control panel and persisted to project config; everything
 * else — secrets and infrastructure — lives exclusively in config/offsite.php
 * and is never persisted to project config or the DB (spec §5-5).
 *
 * fields() is restricted to CP_FIELDS because Craft <= 5.8.21 persists the
 * full toArray() on settings save, and later versions still accept
 * hand-crafted POST fields — hiding secrets from the form is not a boundary.
 *
 * accessKey/secretKey empty => AWS SDK default credential provider chain.
 */
final class Settings extends Model
{
    /** Attributes editable in the CP and persisted to project config. */
    public const CP_FIELDS = [
        'retentionMode',
        'retentionKeepCount',
        'notifyOnSuccess',
        'minFreeDiskMb',
        'multipartThresholdMb',
    ];

    public string $endpoint = '';
    public string $region = '';
    public string $bucket = '';
    public string $keyPrefix = '';
    public string $accessKey = '';
    public string $secretKey = '';
    public string $retentionMode = 'plugin';
    public string $slackWebhookUrl = '';
    public string $notifyEmail = '';
    public string $heartbeatUrl = '';
    public bool $notifyOnSuccess = false;
    public bool $allowInsecureHttp = false;

    // int|string: keep raw CP input so the integer validator can reject it
    // with a proper message — with a plain int type, Craft's Typecast
    // silently casts non-numeric input to 0 before validation runs.
    public int|string $retentionKeepCount = 30;
    public int|string $multipartThresholdMb = 100;
    public int|string $minFreeDiskMb = 2048;

    /** @return array<string, mixed> only CP fields are ever serialized (secrets stay out of project config) */
    public function fields(): array
    {
        return [
            'retentionMode' => 'retentionMode',
            'retentionKeepCount' => fn(): int => (int)$this->retentionKeepCount,
            'notifyOnSuccess' => 'notifyOnSuccess',
            'minFreeDiskMb' => fn(): int => (int)$this->minFreeDiskMb,
            'multipartThresholdMb' => fn(): int => (int)$this->multipartThresholdMb,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['retentionMode', 'retentionKeepCount', 'minFreeDiskMb', 'multipartThresholdMb'], 'required'],
            ['retentionMode', 'in', 'range' => SettingsValidator::RETENTION_MODES, 'strict' => true],
            ['retentionKeepCount', 'integer', 'min' => SettingsValidator::MIN_KEEP_COUNT],
            ['minFreeDiskMb', 'integer', 'min' => SettingsValidator::MIN_FREE_DISK_MB],
            ['multipartThresholdMb', 'integer', 'min' => SettingsValidator::MIN_MULTIPART_MB],
            ['notifyOnSuccess', 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'retentionMode' => 'Retention Mode',
            'retentionKeepCount' => 'Generations to Keep',
            'notifyOnSuccess' => 'Notify on Success',
            'minFreeDiskMb' => 'Minimum Free Disk Space (MB)',
            'multipartThresholdMb' => 'Multipart Upload Threshold (MB)',
        ];
    }

    /** @return array<string, mixed> env-parsed values for validator / factory */
    public function resolved(): array
    {
        return [
            'endpoint' => (string)App::parseEnv($this->endpoint),
            'region' => (string)App::parseEnv($this->region),
            'bucket' => (string)App::parseEnv($this->bucket),
            'keyPrefix' => (string)App::parseEnv($this->keyPrefix),
            'accessKey' => (string)App::parseEnv($this->accessKey),
            'secretKey' => (string)App::parseEnv($this->secretKey),
            'retentionMode' => $this->retentionMode,
            'retentionKeepCount' => (int)$this->retentionKeepCount,
            'slackWebhookUrl' => (string)App::parseEnv($this->slackWebhookUrl),
            'notifyEmail' => (string)App::parseEnv($this->notifyEmail),
            'heartbeatUrl' => (string)App::parseEnv($this->heartbeatUrl),
            'notifyOnSuccess' => $this->notifyOnSuccess,
            'allowInsecureHttp' => $this->allowInsecureHttp,
            'multipartThresholdMb' => (int)$this->multipartThresholdMb,
            'minFreeDiskMb' => (int)$this->minFreeDiskMb,
        ];
    }
}
