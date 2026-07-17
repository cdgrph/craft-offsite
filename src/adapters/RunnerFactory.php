<?php
declare(strict_types=1);

namespace cdgrph\offsite\adapters;

use Aws\S3\S3Client;
use cdgrph\offsite\engine\ArchiveBuilder;
use cdgrph\offsite\engine\BackupRunner;
use cdgrph\offsite\engine\CurlHttpPoster;
use cdgrph\offsite\engine\Destination;
use cdgrph\offsite\engine\HeartbeatPinger;
use cdgrph\offsite\engine\PreflightChecks;
use cdgrph\offsite\engine\RetentionPolicy;
use cdgrph\offsite\engine\RunCatalog;
use cdgrph\offsite\engine\RunContext;
use cdgrph\offsite\engine\RunLock;
use cdgrph\offsite\engine\S3Destination;
use cdgrph\offsite\engine\SettingsValidator;
use cdgrph\offsite\engine\SiteLabel;
use cdgrph\offsite\engine\SlackNotifier;
use cdgrph\offsite\engine\SystemClock;
use cdgrph\offsite\Plugin;
use craft\helpers\StringHelper;

final class RunnerFactory
{
    private array $s;

    public function __construct()
    {
        /** @var \cdgrph\offsite\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $this->s = $settings->resolved();
    }

    /** @return list<string> */
    public function settingsErrors(): array
    {
        return (new SettingsValidator())->validate($this->s);
    }

    public function destination(): Destination
    {
        $config = [
            'version' => 'latest',
            'region' => $this->s['region'] !== '' ? $this->s['region'] : 'us-east-1',
            // aws-sdk-php >= 3.337 defaults to when_supported and adds CRC32 headers to PutObject.
            // when_required keeps checksum-rejection fallback requests genuinely checksum-free.
            // This removes SDK transport CRC32 from putString catalog uploads; backup objects are read-back
            // verified, and catalogs are re-uploadable JSON sent over TLS, so this trade-off is accepted.
            'request_checksum_calculation' => 'when_required',
        ];
        if ($this->s['endpoint'] !== '') {
            $config['endpoint'] = $this->s['endpoint'];
            $config['use_path_style_endpoint'] = true;
        }
        // Without explicit credentials, use the SDK default provider chain (instance profile etc.) — spec §3
        if ($this->s['accessKey'] !== '' && $this->s['secretKey'] !== '') {
            $config['credentials'] = ['key' => $this->s['accessKey'], 'secret' => $this->s['secretKey']];
        }
        return new S3Destination(
            new S3Client($config),
            $this->s['bucket'],
            $this->s['keyPrefix'],
            $this->s['multipartThresholdMb'] * 1024 * 1024,
            verifyTmpDir: $this->workDir(),
        );
    }

    public function catalog(): RunCatalog
    {
        return new RunCatalog($this->destination());
    }

    public function lock(): RunLock
    {
        $lockDir = \Craft::$app->getPath()->getStoragePath() . '/offsite';
        return new RunLock(
            guardPath: $lockDir . '/lock.guard',
            statePath: $lockDir . '/lock.json',
            clock: new SystemClock(),
        );
    }

    public function workDir(): string
    {
        $dir = \Craft::$app->getPath()->getRuntimePath() . '/offsite/work';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function create(): BackupRunner
    {
        $notifiers = [];
        if ($this->s['slackWebhookUrl'] !== '') {
            $notifiers[] = new SlackNotifier(new CurlHttpPoster(), $this->s['slackWebhookUrl']);
        }
        if ($this->s['notifyEmail'] !== '') {
            $notifiers[] = new CraftMailNotifier($this->s['notifyEmail']);
        }
        $heartbeat = $this->s['heartbeatUrl'] !== ''
            ? new HeartbeatPinger(new CurlHttpPoster(), $this->s['heartbeatUrl'])
            : null;

        return new BackupRunner(
            dumper: new CraftDatabaseDumper(),
            archiver: new ArchiveBuilder(),
            dest: $this->destination(),
            catalog: $this->catalog(),
            retention: new RetentionPolicy($this->s['retentionMode'], (int)$this->s['retentionKeepCount']),
            lock: $this->lock(),
            clock: new SystemClock(),
            notifiers: $notifiers,
            heartbeat: $heartbeat,
            preflight: new PreflightChecks($this->workDir(), $this->s['minFreeDiskMb'] * 1024 * 1024),
        );
    }

    public function siteLabel(): string
    {
        $primary = \Craft::$app->getSites()->getPrimarySite();
        return SiteLabel::resolve(baseUrl: $primary->getBaseUrl(), fallbackName: $primary->name);
    }

    public function createContext(): RunContext
    {
        $info = \Craft::$app->getInfo();
        return new RunContext(
            runId: date('Ymd-His') . '-' . StringHelper::randomString(6),
            startedAt: date(DATE_ATOM),
            siteUid: (string)$info->uid,
            craftVersion: \Craft::$app->getVersion(),
            schemaVersion: (string)$info->schemaVersion,
            pluginVersion: Plugin::getInstance()->getVersion(),
            dbDriver: \Craft::$app->getDb()->getDriverName(),
            workDir: $this->workDir(),
            siteName: $this->siteLabel(),
            notifyOnSuccess: (bool)$this->s['notifyOnSuccess'],
        );
    }
}
