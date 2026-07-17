<?php
declare(strict_types=1);

namespace cdgrph\offsite\jobs;

use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\records\RunRow;
use craft\queue\BaseJob;

final class RunBackupJob extends BaseJob
{
    public function execute($queue): void
    {
        $factory = new RunnerFactory();
        if ($factory->settingsErrors() !== []) {
            throw new \RuntimeException('Offsite settings invalid: ' . implode('; ', $factory->settingsErrors()));
        }
        $record = $factory->create()->run($factory->createContext());
        // A cache write failure never retries a committed job — a retry would create a
        // duplicate backup under a different runId (Codex review B4)
        try {
            $row = new RunRow();
            $row->runId = $record->runId;
            $row->startedAt = date('Y-m-d H:i:s', strtotime($record->startedAt));
            $row->backupStatus = $record->backupStatus();
            $row->summaryJson = json_encode($record->toArray());
            $row->save(false);
        } catch (\Throwable $e) {
            \Craft::warning("Offsite: could not save local run cache: {$e->getMessage()}", __METHOD__);
        }
        if (!$record->isCommitted()) {
            throw new \RuntimeException('Backup failed: ' . $record->failureReason());
        }
    }

    protected function defaultDescription(): string
    {
        return 'Offsite: database backup';
    }
}
