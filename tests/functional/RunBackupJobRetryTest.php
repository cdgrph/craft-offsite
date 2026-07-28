<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\jobs\RunBackupJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Retry contract: retry only process death, never thrown job failures.
 * Process death arrives either as a null error (expired-reservation redelivery
 * after whole-worker death) or as a Symfony Process exception (isolate-mode
 * child killed at TTR) — see yii2-queue cli/Command.php:198.
 */
final class RunBackupJobRetryTest extends TestCase
{
    public function testTtrIsDeclaredOnTheJob(): void
    {
        self::assertSame(86_400, (new RunBackupJob(['runId' => 'r1']))->getTtr());
    }

    public function testRetriesRedeliveryAfterWorkerDeath(): void
    {
        self::assertTrue((new RunBackupJob(['runId' => 'r1']))->canRetry(1, null));
    }

    public function testRetriesIsolateModeProcessFailure(): void
    {
        $job = new RunBackupJob(['runId' => 'r1']);
        self::assertTrue($job->canRetry(1, new ProcessRuntimeException('timed out')));
    }

    public function testRetriesConcreteTtrTimeoutException(): void
    {
        // The exact exception the isolate worker raises when a child hits TTR
        $timeout = new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL);
        self::assertTrue((new RunBackupJob(['runId' => 'r1']))->canRetry(1, $timeout));
    }

    public function testNeverRetriesThrownJobFailures(): void
    {
        $job = new RunBackupJob(['runId' => 'r1']);
        self::assertFalse($job->canRetry(1, new \RuntimeException('Backup failed: dump error')));
    }

    public function testRetryBudgetIsOneRetry(): void
    {
        self::assertFalse((new RunBackupJob(['runId' => 'r1']))->canRetry(2, null));
    }

    public function testLegacyPayloadWithoutRunIdNeverRetries(): void
    {
        $legacy = self::legacyPayload();
        self::assertSame('', $legacy->runId);
        self::assertFalse($legacy->canRetry(1, null));
    }

    public function testLegacyPayloadNeverRetriesProcessDeathEither(): void
    {
        // Without a fixed runId a retry would take a second backup under a new id,
        // which is the duplicate this contract exists to prevent
        self::assertFalse(self::legacyPayload()->canRetry(1, new ProcessRuntimeException('killed')));
    }

    /** A job serialized before the runId property existed (pre-upgrade queue rows). */
    private static function legacyPayload(): RunBackupJob
    {
        $class = RunBackupJob::class;
        $job = unserialize(sprintf('O:%d:"%s":0:{}', \strlen($class), $class));
        self::assertInstanceOf(RunBackupJob::class, $job);
        return $job;
    }
}
