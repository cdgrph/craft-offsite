<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\engine\CurlHttpPoster;
use cdgrph\offsite\engine\ErrorSummarizer;
use cdgrph\offsite\engine\RunSummary;
use cdgrph\offsite\engine\SlackNotifier;
use cdgrph\offsite\adapters\CraftMailNotifier;
use cdgrph\offsite\Plugin;
use cdgrph\offsite\records\RunRow;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

final class NotifyController extends Controller
{
    /** Re-sends the outcome notification for a past run (idempotent side-effect replay). */
    public function actionResend(string $runId): int
    {
        $factory = new \cdgrph\offsite\adapters\RunnerFactory();
        if ($factory->settingsErrors() !== []) {
            $this->stderr("Offsite settings invalid:\n - " . implode("\n - ", $factory->settingsErrors()) . "\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $row = RunRow::findOne(['runId' => $runId]);
        if ($row === null) {
            $this->stderr("Run not found in local cache: {$runId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $data = json_decode((string)$row->summaryJson, true);
        $ok = ($data['backupStatus'] ?? '') === 'committed';
        $siteName = $factory->siteLabel();
        $details = [];
        if ($siteName !== '') {
            $details['site'] = $siteName;
        }
        $details['status'] = $data['backupStatus'] ?? 'unknown';
        if (!$ok && !empty($data['failureReason'])) {
            $details['error'] = ErrorSummarizer::summarize($data['failureReason']);
        }
        $summary = new RunSummary(
            runId: $runId,
            ok: $ok,
            message: $ok ? 'Backup succeeded (resent)' : 'Backup failed (resent)',
            details: $details,
        );

        /** @var \cdgrph\offsite\models\Settings $s */
        $s = Plugin::getInstance()->getSettings();
        $resolved = $s->resolved();
        $sent = 0;
        if ($resolved['slackWebhookUrl'] !== '') {
            (new SlackNotifier(new CurlHttpPoster(), $resolved['slackWebhookUrl']))->notify($summary);
            $sent++;
        }
        if ($resolved['notifyEmail'] !== '') {
            (new CraftMailNotifier($resolved['notifyEmail']))->notify($summary);
            $sent++;
        }
        $this->stdout("Resent to {$sent} channel(s).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
