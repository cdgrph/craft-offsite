<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\integration;

use cdgrph\offsite\engine\LockUnavailableException;
use cdgrph\offsite\engine\RunLock;
use cdgrph\offsite\engine\SystemClock;
use PHPUnit\Framework\TestCase;

final class RunLockProcessTest extends TestCase
{
    private string $dir;
    private string $guardPath;
    private string $statePath;

    protected function setUp(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            self::markTestSkipped('proc_open and posix_kill are required.');
        }
        $this->dir = sys_get_temp_dir() . '/offsite-run-lock-process-' . uniqid();
        mkdir($this->dir, 0775, true);
        $this->guardPath = $this->dir . '/lock.guard';
        $this->statePath = $this->dir . '/lock.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->guardPath);
        @unlink($this->statePath);
        @unlink($this->statePath . '.tmp');
        @rmdir($this->dir);
    }

    public function testLockIsUnavailableWhileHolderIsAliveAndReleasedAfterSigkill(): void
    {
        [$process, $pipes, $pid] = $this->startHolder('hold');
        try {
            $this->assertUnavailable();
            posix_kill($pid, SIGKILL);
            $this->acquireBeforeDeadline(3.0);
        } finally {
            $this->stopProcess($process, $pipes, $pid);
        }
    }

    public function testInheritedDescriptorKeepsLockUntilChildExits(): void
    {
        [$process, $pipes, $pid, $childPid] = $this->startHolder('child', '8');
        try {
            posix_kill($pid, SIGKILL);
            $this->waitForParentExit($process, $pid, 3.0);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            $process = null;
            $pipes = [];
            $this->waitForPidGone($pid, 3.0);
            $this->assertUnavailable();
            self::assertTrue(posix_kill($childPid, 0));
            $this->acquireBeforeDeadline(15.0);
        } finally {
            if (posix_kill($childPid, 0)) {
                posix_kill($childPid, SIGKILL);
            }
            if (is_resource($process)) {
                $this->stopProcess($process, $pipes, $pid);
            }
        }
    }

    public function testProgressFailsClosedAfterGuardReplacement(): void
    {
        $lock = new RunLock($this->guardPath, $this->statePath, new SystemClock());
        $lock->acquire('inode-holder');
        try {
            unlink($this->guardPath);
            touch($this->guardPath);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot guarantee mutual exclusion');
            $lock->progress('upload');
        } finally {
            $lock->release('inode-holder');
        }
    }

    public function testAcquireRetriesPastBriefDiagnosticProbe(): void
    {
        [$process, $pipes, $pid] = $this->startHolder('brief', '120');
        try {
            $lock = new RunLock($this->guardPath, $this->statePath, new SystemClock());
            $lock->acquire('contender');
            $lock->release('contender');
            self::assertTrue(true);
        } finally {
            $this->stopProcess($process, $pipes, $pid);
        }
    }

    /** @return array{resource, array<int, resource>, int, int?} */
    private function startHolder(string $mode, string $seconds = '30'): array
    {
        $command = [PHP_BINARY, __DIR__ . '/support/run-lock-holder.php', $this->guardPath, $this->statePath, $mode, $seconds];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 3.0;
        $output = '';
        while (microtime(true) < $deadline && !str_contains($output, "\n")) {
            $output .= (string)fread($pipes[1], 8192);
            usleep(10_000);
        }
        self::assertMatchesRegularExpression('/^READY (\d+)(?: (\d+))?\n$/', $output);
        preg_match('/^READY (\d+)(?: (\d+))?\n$/', $output, $matches);

        return [$process, $pipes, (int)$matches[1], isset($matches[2]) ? (int)$matches[2] : null];
    }

    private function assertUnavailable(): void
    {
        $lock = new RunLock($this->guardPath, $this->statePath, new SystemClock());
        try {
            $lock->acquire('contender');
            $lock->release('contender');
            self::fail('The contender unexpectedly acquired the lock.');
        } catch (LockUnavailableException) {
            self::assertTrue(true);
        }
    }

    private function acquireBeforeDeadline(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $lock = new RunLock($this->guardPath, $this->statePath, new SystemClock());
            try {
                $lock->acquire('contender');
                $lock->release('contender');
                return;
            } catch (LockUnavailableException) {
                usleep(20_000);
            }
        } while (microtime(true) < $deadline);

        self::fail('The lock was not released before the deadline.');
    }

    /** @param resource $process */
    private function waitForParentExit($process, int $pid, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail("Holder process {$pid} did not exit before the deadline.");
    }

    private function waitForPidGone(int $pid, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (!posix_kill($pid, 0)) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail("Holder PID {$pid} remained visible after the process was reaped.");
    }

    /** @param resource $process @param array<int, resource> $pipes */
    private function stopProcess($process, array $pipes, int $pid): void
    {
        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
}
