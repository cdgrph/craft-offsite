<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\records\RunRow;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Runs off-site database backups. Schedule with cron:
 *   0 3 * * * /usr/bin/php /path/to/craft offsite/backup/db
 */
final class BackupController extends Controller
{
    public function actionDb(): int
    {
        $factory = new RunnerFactory();
        $errors = $factory->settingsErrors();
        if ($errors !== []) {
            $this->stderr("Offsite settings invalid:\n - " . implode("\n - ", $errors) . "\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $ctx = $factory->createContext();
        $this->stdout("Starting backup run {$ctx->runId}...\n");
        $record = $factory->create()->run($ctx);

        // Save to the local UI cache (the remote catalog is the source of truth). A
        // cache write failure never taints the exit code of a committed backup (Codex review B4)
        try {
            $row = new RunRow();
            $row->runId = $record->runId;
            $row->startedAt = date('Y-m-d H:i:s', strtotime($record->startedAt));
            $row->backupStatus = $record->backupStatus();
            $row->summaryJson = json_encode($record->toArray());
            $row->save(false);
        } catch (\Throwable $e) {
            $this->stderr("Warning: could not save local run cache: {$e->getMessage()}\n", Console::FG_YELLOW);
        }

        foreach ($record->sideEffects() as $name => $result) {
            $mark = $result['ok'] ? 'ok' : "FAILED ({$result['error']})";
            $this->stdout("  {$name}: {$mark}\n");
        }

        if ($record->isCommitted()) {
            $this->stdout("Backup committed: {$record->runId}\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr("Backup FAILED: {$record->failureReason()}\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
