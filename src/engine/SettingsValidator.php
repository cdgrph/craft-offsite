<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class SettingsValidator
{
    /** Shared with models\Settings::defineRules() so CP validation cannot drift. */
    public const RETENTION_MODES = [RetentionPolicy::MODE_PLUGIN, RetentionPolicy::MODE_LIFECYCLE];
    public const MIN_KEEP_COUNT = 1;
    public const MIN_MULTIPART_MB = 5;
    public const MIN_FREE_DISK_MB = 1;

    /** @param array<string, mixed> $s resolved (env-parsed) settings @return list<string> channel labels */
    public function monitoringChannels(array $s): array
    {
        $channels = [];
        foreach ([
            'slackWebhookUrl' => 'slack webhook',
            'notifyEmail' => 'email',
            'heartbeatUrl' => 'heartbeat',
        ] as $setting => $label) {
            if (($s[$setting] ?? '') !== '') {
                $channels[] = $label;
            }
        }
        return $channels;
    }

    /** @param array<string, mixed> $s resolved (env-parsed) settings @return list<string> warnings */
    public function warnings(array $s): array
    {
        if ($this->monitoringChannels($s) !== []) {
            return [];
        }
        return [
            'No notification channel is configured (slackWebhookUrl, notifyEmail, heartbeatUrl are all empty). Backup failures will not trigger any alert — they are only visible in the control panel utility and your cron log. Configure at least a heartbeat: it is the only channel that detects silence — a run that never starts because cron is broken, or a process that dies before it can send a failure notification.',
        ];
    }

    /** @param array<string, mixed> $s resolved (env-parsed) settings @return list<string> errors */
    public function validate(array $s): array
    {
        $errors = [];
        if (($s['bucket'] ?? '') === '') {
            $errors[] = 'bucket is required.';
        }
        if (($s['endpoint'] ?? '') === '' && ($s['region'] ?? '') === '') {
            $errors[] = 'region is required when no custom endpoint is set.';
        }
        $mode = $s['retentionMode'] ?? 'plugin';
        if (!\in_array($mode, self::RETENTION_MODES, true)) {
            $errors[] = "retentionMode must be 'plugin' or 'lifecycle', got '{$mode}'.";
        }
        if ((int)($s['retentionKeepCount'] ?? 0) < self::MIN_KEEP_COUNT) {
            $errors[] = 'retentionKeepCount must be >= 1.';
        }

        // TLS is mandatory (spec §5-5). http is allowed only via explicit opt-in for development (Codex review B9)
        $allowInsecure = (bool)($s['allowInsecureHttp'] ?? false);
        foreach (['heartbeatUrl', 'slackWebhookUrl', 'endpoint'] as $urlField) {
            $v = (string)($s[$urlField] ?? '');
            if ($v === '') {
                continue;
            }
            if (!preg_match('#^https?://#', $v)) {
                $errors[] = "{$urlField} must be an http(s) URL.";
            } elseif (!$allowInsecure && !str_starts_with($v, 'https://')) {
                $errors[] = "{$urlField} must use https. Set allowInsecureHttp for local development only.";
            }
        }

        // A one-sided credential silently falls through to the SDK chain while the
        // operator believes explicit credentials are in use (Codex review S14)
        if ((($s['accessKey'] ?? '') !== '') !== (($s['secretKey'] ?? '') !== '')) {
            $errors[] = 'accessKey and secretKey must be set together (leave both empty to use the SDK credential chain).';
        }
        if ((int)($s['multipartThresholdMb'] ?? 100) < self::MIN_MULTIPART_MB) {
            $errors[] = 'multipartThresholdMb must be >= 5 (the S3 multipart minimum part size).';
        }
        if ((int)($s['minFreeDiskMb'] ?? 2048) < self::MIN_FREE_DISK_MB) {
            $errors[] = 'minFreeDiskMb must be >= 1.';
        }
        return $errors;
    }
}
