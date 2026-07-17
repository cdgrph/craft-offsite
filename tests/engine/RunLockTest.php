<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\LockUnavailableException;
use cdgrph\offsite\engine\RunLock;
use cdgrph\offsite\tests\support\FakeClock;
use PHPUnit\Framework\TestCase;

final class RunLockTest extends TestCase
{
    private string $dir;
    private string $guardPath;
    private string $statePath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/offsite-run-lock-' . uniqid();
        mkdir($this->dir, 0775, true);
        $this->guardPath = $this->dir . '/lock.guard';
        $this->statePath = $this->dir . '/lock.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->guardPath);
        @unlink($this->statePath);
        @unlink($this->statePath . '.tmp');
        @rmdir($this->statePath . '.tmp');
        @rmdir($this->dir);
    }

    private function lock(FakeClock $clock): RunLock
    {
        return new RunLock($this->guardPath, $this->statePath, $clock);
    }

    public function testSameInstanceCannotAcquireTwice(): void
    {
        $lock = $this->lock(new FakeClock());
        $lock->acquire('owner-a');

        $this->expectException(\LogicException::class);
        $lock->acquire('owner-a');
    }

    public function testAnotherInstanceCannotAcquireEvenForSameOwner(): void
    {
        $clock = new FakeClock();
        $first = $this->lock($clock);
        $second = $this->lock($clock);
        $first->acquire('owner-a');

        $this->expectException(LockUnavailableException::class);
        $second->acquire('owner-a');
    }

    public function testAdvancingClockNeverAllowsTakeover(): void
    {
        $clock = new FakeClock();
        $first = $this->lock($clock);
        $second = $this->lock($clock);
        $first->acquire('owner-a');
        $clock->t += 10_000_000;

        $this->expectException(LockUnavailableException::class);
        $second->acquire('owner-b');
    }

    public function testReleaseByNonOwnerDoesNotUnlock(): void
    {
        $clock = new FakeClock();
        $first = $this->lock($clock);
        $second = $this->lock($clock);
        $first->acquire('owner-a');
        $first->release('owner-b');

        $this->expectException(LockUnavailableException::class);
        $second->acquire('owner-b');
    }

    public function testReleaseTwiceIsSafe(): void
    {
        $clock = new FakeClock();
        $first = $this->lock($clock);
        $first->acquire('owner-a');
        $first->release('owner-a');
        $first->release('owner-a');

        $second = $this->lock($clock);
        $second->acquire('owner-b');
        $second->release('owner-b');
        self::assertTrue(true);
    }

    /** @dataProvider unavailableMetadataProvider */
    public function testUnavailableLockDegradesWhenHolderMetadataIsUnavailable(?string $state): void
    {
        $clock = new FakeClock();
        $first = $this->lock($clock);
        $first->acquire('owner-a');
        if ($state === null) {
            unlink($this->statePath);
        } else {
            file_put_contents($this->statePath, $state);
        }

        try {
            $this->lock($clock)->acquire('owner-b');
            self::fail('The second lock unexpectedly acquired the guard.');
        } catch (LockUnavailableException $e) {
            self::assertStringContainsString('Another Offsite run holds the lock', $e->getMessage());
            self::assertStringContainsString('holder metadata unavailable', $e->getMessage());
            self::assertStringNotContainsString('owner-a', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string|null}> */
    public static function unavailableMetadataProvider(): iterable
    {
        yield 'missing state' => [null];
        yield 'corrupt state' => ['not-json{{'];
        yield 'released state' => [json_encode([
            'owner' => 'old-owner',
            'pid' => 123,
            'acquiredAt' => 100,
            'currentPhase' => 'upload',
            'phaseStartedAt' => 200,
            'status' => 'released',
        ], JSON_THROW_ON_ERROR)];
    }

    public function testUnavailableLockReportsActiveMetadataAsPossiblyStale(): void
    {
        $clock = new FakeClock(2000);
        $first = $this->lock($clock);
        $first->acquire('owner-a');

        try {
            $this->lock($clock)->acquire('owner-b');
            self::fail('The second lock unexpectedly acquired the guard.');
        } catch (LockUnavailableException $e) {
            self::assertStringContainsString('last recorded holder: owner-a', $e->getMessage());
            self::assertStringContainsString('phase acquired', $e->getMessage());
            self::assertStringContainsString('php craft offsite/diagnose', $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
            self::assertStringNotContainsString($this->guardPath, $e->getMessage());
            self::assertStringNotContainsString('lsof', $e->getMessage());
        }
    }

    public function testFailedInitialStateWriteClosesFileDescriptor(): void
    {
        mkdir($this->statePath, 0775);
        $first = $this->lock(new FakeClock());

        try {
            $first->acquire('owner-a');
            self::fail('Writing state over a directory unexpectedly succeeded.');
        } catch (\RuntimeException) {
            rmdir($this->statePath);
        }

        $second = $this->lock(new FakeClock());
        $second->acquire('owner-b');
        $second->release('owner-b');
        self::assertTrue(true);
    }

    public function testProgressUpdatesCurrentPhaseAndStartTime(): void
    {
        $clock = new FakeClock(1000);
        $lock = $this->lock($clock);
        $lock->acquire('owner-a');
        $clock->t = 1250;
        $lock->progress('upload');

        $state = json_decode((string)file_get_contents($this->statePath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('owner-a', $state['owner']);
        self::assertSame(1000, $state['acquiredAt']);
        self::assertSame('upload', $state['currentPhase']);
        self::assertSame(1250, $state['phaseStartedAt']);
        self::assertSame('active', $state['status']);
    }

    public function testProgressIgnoresDiagnosticStateWriteFailure(): void
    {
        $lock = $this->lock(new FakeClock());
        $lock->acquire('owner-a');
        unlink($this->statePath);
        mkdir($this->statePath);

        $lock->progress('upload');

        self::assertDirectoryExists($this->statePath);
        rmdir($this->statePath);
        $lock->release('owner-a');
    }

    public function testReleaseIgnoresDiagnosticStateWriteFailureAndUnlocks(): void
    {
        $clock = new FakeClock();
        $lock = $this->lock($clock);
        $lock->acquire('owner-a');
        unlink($this->statePath);
        mkdir($this->statePath);

        $lock->release('owner-a');

        rmdir($this->statePath);
        $contender = $this->lock($clock);
        $contender->acquire('owner-b');
        $contender->release('owner-b');
        self::assertTrue(true);
    }

    public function testDiagnosticsTreatsMissingGuardAsFreshInstall(): void
    {
        $lines = $this->lock(new FakeClock())->diagnostics();

        self::assertContains('lock availability: available (no run has taken the lock yet)', $lines);
        self::assertStringContainsString('Never delete the guard file.', implode("\n", $lines));
    }

    public function testDiagnosticsUsesValidatedStateAndWarnsBeforeKillingImport(): void
    {
        $clock = new FakeClock(2000);
        $holder = $this->lock($clock);
        $holder->acquire('restore:run-1');
        $holder->progress('import');

        $diagnostics = implode("\n", $this->lock($clock)->diagnostics());

        self::assertStringContainsString('lock availability: HELD', $diagnostics);
        self::assertStringContainsString('last recorded holder : restore:run-1 (may be stale)', $diagnostics);
        self::assertStringContainsString('Never delete the guard file.', $diagnostics);
        self::assertStringContainsString('Do NOT kill it', $diagnostics);
        self::assertStringContainsString('storage/runtime/offsite/work/', $diagnostics);
        self::assertStringContainsString('lsof ' . $this->guardPath, $diagnostics);
    }

    public function testDiagnosticsRejectsMalformedActiveState(): void
    {
        file_put_contents($this->statePath, '{"status":"active"}');

        $diagnostics = implode("\n", $this->lock(new FakeClock())->diagnostics());

        self::assertStringContainsString('holder metadata unavailable', $diagnostics);
        self::assertStringNotContainsString('last recorded holder', $diagnostics);
    }
}
