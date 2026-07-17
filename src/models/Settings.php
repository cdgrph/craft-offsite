<?php
declare(strict_types=1);

namespace cdgrph\offsite\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Secrets are never persisted to project config or the DB: this settings
 * model is populated exclusively from config/offsite.php (spec §5-5).
 * accessKey/secretKey empty => AWS SDK default credential provider chain.
 */
final class Settings extends Model
{
    public string $endpoint = '';
    public string $region = '';
    public string $bucket = '';
    public string $keyPrefix = '';
    public string $accessKey = '';
    public string $secretKey = '';
    public string $retentionMode = 'plugin';
    public int $retentionKeepCount = 30;
    public string $slackWebhookUrl = '';
    public string $notifyEmail = '';
    public string $heartbeatUrl = '';
    public bool $notifyOnSuccess = false;
    public bool $allowInsecureHttp = false;
    public int $multipartThresholdMb = 100;
    public int $minFreeDiskMb = 2048;

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
            'retentionKeepCount' => $this->retentionKeepCount,
            'slackWebhookUrl' => (string)App::parseEnv($this->slackWebhookUrl),
            'notifyEmail' => (string)App::parseEnv($this->notifyEmail),
            'heartbeatUrl' => (string)App::parseEnv($this->heartbeatUrl),
            'notifyOnSuccess' => $this->notifyOnSuccess,
            'allowInsecureHttp' => $this->allowInsecureHttp,
            'multipartThresholdMb' => $this->multipartThresholdMb,
            'minFreeDiskMb' => $this->minFreeDiskMb,
        ];
    }
}
