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
        // The cache is a display convenience; the remote catalog is the source of
        // truth. Never fail a committed job over it — that would report a successful
        // backup as failed in the control panel and to the notification channels.
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
        // One death-retry budget. The queue asks twice per delivery with different
        // bases — before running a redelivered message it passes $attempt - 1, and
        // after a failure it passes the current $attempt — so this bound lets the
        // job body run at most twice on either path. An empty runId means a payload
        // queued before the runId property existed: retrying it would run a second
        // backup under a new id, which is exactly what the fixed runId prevents.
        if ($attempt >= 2 || $this->runId === '') {
            return false;
        }
        // Only the process dying is worth another attempt, and it reaches us in two
        // shapes:
        //   - null: a reservation expired without the worker reporting anything,
        //     i.e. the whole worker died (a reboot or a deploy).
        //   - a Symfony Process exception: a queue worker in isolated mode could not
        //     get a clean result out of the job's child process — it hit the TTR
        //     limit, was killed, or died on a fatal error.
        // A failure the backup itself reports is a plain throwable and never
        // retries: the child classifies it and exits normally, and a worker running
        // without isolation raises it directly.
        return $error === null || $error instanceof ProcessExceptionInterface;
    }

    protected function defaultDescription(): string
    {
        return 'Offsite: database backup';
    }
}
