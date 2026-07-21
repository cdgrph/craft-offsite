<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\CraftDatabaseDumper;
use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\engine\SettingsValidator;
use cdgrph\offsite\engine\SiteLabel;
use cdgrph\offsite\Plugin;
use cdgrph\offsite\records\RunRow;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Prints a redacted diagnostic bundle for support requests (spec §6).
 * NEVER prints credentials.
 */
final class DiagnoseController extends Controller
{
    public function actionIndex(): int
    {
        $factory = new RunnerFactory();
        /** @var \cdgrph\offsite\models\Settings $s */
        $s = Plugin::getInstance()->getSettings();
        $resolved = $s->resolved();
        $validator = new SettingsValidator();
        $db = \Craft::$app->getDb();

        $lines = [
            'Offsite version: ' . Plugin::getInstance()->getVersion(),
            'Craft version: ' . \Craft::$app->getVersion(),
            'PHP version: ' . PHP_VERSION,
            'DB driver: ' . $db->getDriverName(),
        ];
        if (CraftDatabaseDumper::backupCommandDisabled()) {
            $lines[] = 'warning: backupCommand is set to false — Craft database backups are disabled, so offsite/backup/db will always fail in this environment. If your hosting platform manages database backups (e.g. Craft Cloud), use the platform\'s backups instead.';
        }
        if ($db->getIsPgsql()) {
            $general = \Craft::$app->getConfig()->getGeneral();
            if (!property_exists($general, 'backupCommandFormat')) {
                $format = 'plain (predates backupCommandFormat)';
            } elseif ($general->backupCommandFormat === null) {
                $format = 'plain (default)';
            } else {
                $format = $general->backupCommandFormat;
            }
            $lines[] = 'backup command format: ' . $format;
            $unsupportedFormat = CraftDatabaseDumper::unsupportedBackupFormat();
            if ($unsupportedFormat !== null) {
                $lines[] = "warning: backupCommandFormat '{$unsupportedFormat}' is not supported — Offsite requires Craft's plain-SQL backup format. Backups will fail until it is reverted.";
            }
        }
        array_push(
            $lines,
            'endpoint: ' . ($resolved['endpoint'] ?: '(AWS default)'),
            'region: ' . $resolved['region'],
            'bucket: ' . $resolved['bucket'],
            'credentials: ' . ($resolved['accessKey'] !== '' ? 'static key (redacted)' : 'SDK default chain'),
            'retention: ' . $resolved['retentionMode'] . ' keep=' . $resolved['retentionKeepCount'],
            'settings errors: ' . (implode('; ', $factory->settingsErrors()) ?: 'none'),
        );
        $channels = $validator->monitoringChannels($resolved);
        $lines[] = 'monitoring channels: ' . (implode(', ', $channels) ?: 'NONE');
        foreach ($validator->warnings($resolved) as $warning) {
            $lines[] = 'warning: ' . $warning;
        }
        $primary = \Craft::$app->getSites()->getPrimarySite();
        $baseUrl = $primary->getBaseUrl();
        $lines[] = 'notification site label: ' . $factory->siteLabel();
        if (!SiteLabel::hasResolvableHost($baseUrl)) {
            $lines[] = "warning: the primary site's base URL (" . var_export($baseUrl, true) . ") has no resolvable host in console context, so notifications fall back to the site display name. If the base URL uses the @web alias, define @web in config/general.php 'aliases', or use an absolute URL / environment variable instead.";
        }
        $lines[] = 'free disk (work dir): ' . (string)disk_free_space($factory->workDir());
        array_push($lines, ...$factory->lock()->diagnostics());
        // Never talk to the network with credentials while settings are invalid
        // (insecure endpoint etc.) — only display the diagnostics (Codex review F3)
        if ($factory->settingsErrors() === []) {
            try {
                $count = \count($factory->catalog()->all());
                $lines[] = "connectivity: ok ({$count} catalog entries)";
            } catch (\Throwable $e) {
                $lines[] = 'connectivity: FAILED — ' . $e->getMessage();
            }
        } else {
            $lines[] = 'connectivity: skipped (settings invalid — fix the errors above first)';
        }
        foreach (RunRow::find()->orderBy(['startedAt' => SORT_DESC])->limit(3)->all() as $row) {
            $lines[] = "recent run: {$row->runId} {$row->backupStatus}";
        }
        $this->stdout(implode("\n", $lines) . "\n");
        return ExitCode::OK;
    }
}
