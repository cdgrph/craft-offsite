<?php
declare(strict_types=1);

namespace cdgrph\offsite\jobs;

use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\records\RunRow;
use craft\queue\BaseJob;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use yii\queue\RetryableJobInterface;

final class RunBackupJob extends BaseJob implements RetryableJobInterface
{
    /**
     * Fixed at push time and serialized with the job so every retry attempt
     * targets the same backup run: BackupRunner resumes instead of re-running
     * when the catalog already has this runId. Empty only for legacy payloads
     * serialized before this property existed — those run with a fresh runId
     * and never retry (a retry without a fixed runId would duplicate backups).
     */
    public string $runId = '';

    public function execute($queue): void
    {
        $factory = new RunnerFactory();
        if ($factory->settingsErrors() !== []) {
            throw new \RuntimeException('Offsite settings invalid: ' . implode('; ', $factory->settingsErrors()));
        }
        $record = $factory->create()->run(
            $factory->createContext($this->runId !== '' ? $this->runId : null),
        );
        // A cache write failure never retries a committed job — a retry would create a
        // duplicate backup under a different runId (Codex review B4)
        $cacheError = RunRow::upsertFromRecord($record);
        if ($cacheError !== null) {
            \Craft::warning("Offsite: could not save local run cache: {$cacheError}", __METHOD__);
        }
        if (!$record->isCommitted()) {
            throw new \RuntimeException('Backup failed: ' . $record->failureReason());
        }
    }

    public function getTtr(): int
    {
        // If the isolate-mode child is killed at TTR, the inherited mysqldump is
        // orphaned while holding the lock and blocks every later run, so declare
        // a generous explicit limit (see RunLock).
        return 86_400;
    }

    public function canRetry($attempt, $error): bool
    {
        if ($attempt >= 2 || $this->runId === '') {
            return false; // one death-retry budget; legacy payloads never retry
        }
        // Process death arrives in exactly two shapes (yii2-queue cli/Command.php:198):
        //   - null: redelivery of an expired reservation (whole-worker death)
        //   - Symfony Process exception: isolate-mode infra failure (TTR timeout,
        //     child killed) reported by the parent worker
        // The job's own exceptions are classified inside the isolate child and
        // reach this method as non-Process throwables — those never retry.
        return $error === null || $error instanceof ProcessExceptionInterface;
    }

    protected function defaultDescription(): string
    {
        return 'Offsite: database backup';
    }
}
