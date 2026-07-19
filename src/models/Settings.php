<?php
declare(strict_types=1);

namespace cdgrph\offsite\models;

use cdgrph\offsite\engine\SettingsValidator;
use craft\base\Model;
use craft\helpers\App;

/**
 * Hybrid settings: the control panel exposes five operational settings and
 * nine connection / notification settings that only accept environment
 * variable references. Raw secrets are rejected by validation and blocked
 * again by the serialization guard before project config persistence.
 *
 * config/offsite.php can continue to override every setting, including with
 * raw values that are never exposed through fields().
 */
final class Settings extends Model
{
    public const ENV_REFERENCE_FIELDS = [
        'endpoint',
        'region',
        'bucket',
        'keyPrefix',
        'accessKey',
        'secretKey',
        'slackWebhookUrl',
        'notifyEmail',
        'heartbeatUrl',
    ];

    /** Attributes editable in the CP and persisted to project config. */
    public const CP_FIELDS = [
        'retentionMode',
        'retentionKeepCount',
        'notifyOnSuccess',
        'minFreeDiskMb',
        'multipartThresholdMb',
        ...self::ENV_REFERENCE_FIELDS,
    ];

    private const ENV_REF_PATTERN = '/^\$\w+$/D';

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

    /**
     * Keys supplied by config/offsite.php.
     *
     * This stays private and is populated through a setter instead of becoming
     * an attribute, so Craft's setSettings() -> setAttributes(..., false) mass
     * assignment cannot reach it.
     *
     * @var list<string>
     */
    private array $configFileKeys = [];

    /** @param list<string> $keys */
    public function setConfigFileKeys(array $keys): void
    {
        $this->configFileKeys = $keys;
    }

    /** @return list<string> */
    public function getConfigFileKeys(): array
    {
        return $this->configFileKeys;
    }

    /**
     * CP form display helper — never renders a raw (non-reference) value, so
     * secrets injected from config/offsite.php stay out of the settings page HTML.
     */
    public function cpDisplayValue(string $attribute): string
    {
        return $this->envRefOrBlank((string)$this->$attribute);
    }

    /** @return array<string, mixed> only CP fields are ever serialized */
    public function fields(): array
    {
        return [
            'retentionMode' => 'retentionMode',
            'retentionKeepCount' => fn(): int => (int)$this->retentionKeepCount,
            'notifyOnSuccess' => 'notifyOnSuccess',
            'minFreeDiskMb' => fn(): int => (int)$this->minFreeDiskMb,
            'multipartThresholdMb' => fn(): int => (int)$this->multipartThresholdMb,
            'endpoint' => fn(): string => $this->envRefOrBlank($this->endpoint),
            'region' => fn(): string => $this->envRefOrBlank($this->region),
            'bucket' => fn(): string => $this->envRefOrBlank($this->bucket),
            'keyPrefix' => fn(): string => $this->envRefOrBlank($this->keyPrefix),
            'accessKey' => fn(): string => $this->envRefOrBlank($this->accessKey),
            'secretKey' => fn(): string => $this->envRefOrBlank($this->secretKey),
            'slackWebhookUrl' => fn(): string => $this->envRefOrBlank($this->slackWebhookUrl),
            'notifyEmail' => fn(): string => $this->envRefOrBlank($this->notifyEmail),
            'heartbeatUrl' => fn(): string => $this->envRefOrBlank($this->heartbeatUrl),
        ];
    }

    /**
     * Craft <= 5.8.21 persists the full toArray() when saving settings, which
     * could otherwise leak raw values injected from config/offsite.php into
     * project config. Validation improves user-input UX; this serialization
     * guard is the final persistence boundary.
     */
    private function envRefOrBlank(string $value): string
    {
        return \preg_match(self::ENV_REF_PATTERN, $value) === 1 ? $value : '';
    }

    protected function defineRules(): array
    {
        return [
            [['retentionMode', 'retentionKeepCount', 'minFreeDiskMb', 'multipartThresholdMb'], 'required'],
            // Mirror engine\SettingsValidator::validate() so incomplete setup fails on save.
            // Presence is checked on the raw attributes (env reference or config-file value);
            // resolved values stay the engine validator's responsibility at run time.
            // Config-file overrides are exempt because the CP cannot edit their values and
            // must never be blocked from saving by them.
            [
                'bucket',
                'required',
                'message' => 'Bucket is required — enter an environment variable reference like $OFFSITE_BUCKET (put the real value in .env).',
                'when' => fn(self $model): bool => !\in_array('bucket', $model->getConfigFileKeys(), true),
            ],
            [
                'region',
                'required',
                'message' => 'Region is required when no custom endpoint is set — enter an environment variable reference like $OFFSITE_REGION.',
                'when' => fn(self $model): bool => $model->endpoint === ''
                    && !\in_array('region', $model->getConfigFileKeys(), true),
            ],
            ['retentionMode', 'in', 'range' => SettingsValidator::RETENTION_MODES, 'strict' => true],
            ['retentionKeepCount', 'integer', 'min' => SettingsValidator::MIN_KEEP_COUNT],
            ['minFreeDiskMb', 'integer', 'min' => SettingsValidator::MIN_FREE_DISK_MB],
            ['multipartThresholdMb', 'integer', 'min' => SettingsValidator::MIN_MULTIPART_MB],
            ['notifyOnSuccess', 'boolean'],
            [
                self::ENV_REFERENCE_FIELDS,
                'match',
                'pattern' => self::ENV_REF_PATTERN,
                'message' => '{attribute} must be an environment variable reference like $OFFSITE_SECRET_KEY (put the real value in .env), or blank.',
                // config/offsite.php injects raw values into model attributes.
                // Skipping those keys keeps existing config-file users able to
                // save CP settings; the serialization guard still blocks leaks.
                'when' => fn(self $model, string $attribute): bool => !\in_array($attribute, $model->getConfigFileKeys(), true),
            ],
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
            'endpoint' => 'S3 Endpoint URL',
            'region' => 'Region',
            'bucket' => 'Bucket',
            'keyPrefix' => 'Key Prefix',
            'accessKey' => 'Access Key ID',
            'secretKey' => 'Secret Access Key',
            'slackWebhookUrl' => 'Slack Webhook URL',
            'notifyEmail' => 'Notification Email',
            'heartbeatUrl' => 'Heartbeat URL',
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
