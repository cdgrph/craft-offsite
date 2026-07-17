<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\RunRecord;
use PHPUnit\Framework\TestCase;

final class RunRecordTest extends TestCase
{
    public function testLifecycleHappyPath(): void
    {
        $r = new RunRecord('run-1', '2026-07-12T00:00:00+00:00');
        self::assertSame(RunRecord::PREPARED, $r->backupStatus());
        $r->markUploading();
        $r->markCommitted();
        self::assertTrue($r->isCommitted());
    }

    public function testCommittedIsImmutable(): void
    {
        $r = new RunRecord('run-1', '2026-07-12T00:00:00+00:00');
        $r->markUploading();
        $r->markCommitted();
        $this->expectException(\LogicException::class);
        $r->markFailed('too late');
    }

    public function testSideEffectFailureDoesNotFlipBackupStatus(): void
    {
        $r = new RunRecord('run-1', '2026-07-12T00:00:00+00:00');
        $r->markUploading();
        $r->markCommitted();
        $r->recordSideEffect('notify:slack', false, 'HTTP 500');
        self::assertTrue($r->isCommitted());
        self::assertSame(['ok' => false, 'error' => 'HTTP 500'], $r->sideEffects()['notify:slack']);
    }

    public function testInvalidTransitionRejected(): void
    {
        $r = new RunRecord('run-1', '2026-07-12T00:00:00+00:00');
        $this->expectException(\LogicException::class);
        $r->markCommitted(); // Committing straight from prepared is not allowed
    }

    public function testRoundTripSerialization(): void
    {
        $r = new RunRecord('run-1', '2026-07-12T00:00:00+00:00');
        $r->markUploading();
        $r->markFailed('disk full');
        $copy = RunRecord::fromArray($r->toArray());
        self::assertSame(RunRecord::FAILED, $copy->backupStatus());
        self::assertSame('disk full', $copy->failureReason());
    }
}
