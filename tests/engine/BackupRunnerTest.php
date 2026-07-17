<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\ArchiveBuilder;
use cdgrph\offsite\engine\BackupRunner;
use cdgrph\offsite\engine\CatalogEntry;
use cdgrph\offsite\engine\DatabaseDumper;
use cdgrph\offsite\engine\Destination;
use cdgrph\offsite\engine\HeartbeatPinger;
use cdgrph\offsite\engine\Notifier;
use cdgrph\offsite\engine\PreflightChecks;
use cdgrph\offsite\engine\RetentionPolicy;
use cdgrph\offsite\engine\RunCatalog;
use cdgrph\offsite\engine\RunContext;
use cdgrph\offsite\engine\RunLock;
use cdgrph\offsite\engine\RunRecord;
use cdgrph\offsite\engine\RunSummary;
use cdgrph\offsite\engine\SlackNotifier;
use cdgrph\offsite\tests\support\FakeClock;
use cdgrph\offsite\tests\support\FakeDestination;
use cdgrph\offsite\tests\support\FakeDumper;
use cdgrph\offsite\tests\support\FakeHttpPoster;
use PHPUnit\Framework\TestCase;

final class BackupRunnerTest extends TestCase
{
    private string $workDir;
    private FakeDestination $dest;
    private FakeDumper $dumper;
    private FakeHttpPoster $slackHttp;
    private FakeHttpPoster $hbHttp;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/offsite-runner-' . uniqid();
        mkdir($this->workDir, 0775, true);
        $this->dest = new FakeDestination();
        $this->dumper = new FakeDumper();
        $this->slackHttp = new FakeHttpPoster();
        $this->hbHttp = new FakeHttpPoster();
    }

    private function runner(int $keepCount = 3, ?RunLock $lock = null, ?Destination $dest = null): BackupRunner
    {
        $dest ??= $this->dest;
        return new BackupRunner(
            dumper: $this->dumper,
            archiver: new ArchiveBuilder(),
            dest: $dest,
            catalog: new RunCatalog($dest),
            retention: new RetentionPolicy('plugin', $keepCount),
            lock: $lock ?? $this->lock(),
            clock: new FakeClock(),
            notifiers: [new SlackNotifier($this->slackHttp, 'https://hooks.example/x')],
            heartbeat: new HeartbeatPinger($this->hbHttp, 'https://hc-ping.com/uuid'),
            preflight: new PreflightChecks($this->workDir, 1, fn() => PHP_INT_MAX),
        );
    }

    private function lock(?FakeClock $clock = null): RunLock
    {
        return new RunLock(
            $this->workDir . '/lock.guard',
            $this->workDir . '/lock.json',
            $clock ?? new FakeClock(),
        );
    }

    private function ctx(string $runId, string $startedAt = '2026-07-12T00:00:00+00:00'): RunContext
    {
        return new RunContext(
            runId: $runId, startedAt: $startedAt, siteUid: 'site-1',
            craftVersion: '5.8.0', schemaVersion: 's', pluginVersion: '1.0.0',
            dbDriver: 'mysql', workDir: $this->workDir,
        );
    }

    public function testHappyPathCommitsPublishesCatalogAndPingsHeartbeat(): void
    {
        $record = $this->runner()->run($this->ctx('run-1'));

        self::assertTrue($record->isCommitted());
        self::assertArrayHasKey('catalog/run-1.json', $this->dest->objects);
        self::assertArrayHasKey('db/run-1.zip', $this->dest->objects);
        // On success: heartbeat GETs the base URL; Slack is not called because notifyOnSuccess=false
        self::assertSame('https://hc-ping.com/uuid', $this->hbHttp->calls[0]['url']);
        self::assertSame([], $this->slackHttp->calls);
        // Temp files are cleaned up
        self::assertSame([], glob($this->workDir . '/*.sql') ?: []);
        self::assertSame([], glob($this->workDir . '/*.zip') ?: []);
    }

    public function testUploadFailureMarksFailedNotifiesAndPingsFail(): void
    {
        $this->dest->failOnKeySubstring = 'db/';
        $record = $this->runner()->run($this->ctx('run-1'));

        self::assertSame(RunRecord::FAILED, $record->backupStatus());
        // The catalog is not published (no commit evidence)
        self::assertArrayNotHasKey('catalog/run-1.json', $this->dest->objects);
        // Failure notification is mandatory behavior
        self::assertNotEmpty($this->slackHttp->calls);
        self::assertSame('https://hc-ping.com/uuid/fail', $this->hbHttp->calls[0]['url']);
    }

    public function testDumpFailureIsFailedRun(): void
    {
        $this->dumper->fail = true;
        $record = $this->runner()->run($this->ctx('run-1'));
        self::assertSame(RunRecord::FAILED, $record->backupStatus());
        self::assertStringContainsString('mysqldump', (string)$record->failureReason());
    }

    public function testPreflightFailureFailsBeforeDump(): void
    {
        $runner = new BackupRunner(
            dumper: $this->dumper, archiver: new ArchiveBuilder(), dest: $this->dest,
            catalog: new RunCatalog($this->dest), retention: new RetentionPolicy('plugin', 3),
            lock: $this->lock(),
            clock: new FakeClock(),
            notifiers: [new SlackNotifier($this->slackHttp, 'https://hooks.example/x')],
            heartbeat: null,
            preflight: new PreflightChecks($this->workDir, PHP_INT_MAX, fn() => 1), // Insufficient free disk space
        );
        $record = $runner->run($this->ctx('run-1'));
        self::assertSame(RunRecord::FAILED, $record->backupStatus());
        self::assertStringContainsString('disk', strtolower((string)$record->failureReason()));
    }

    public function testRetentionDeletesOldGenerationsAfterCommit(): void
    {
        $runner = $this->runner(keepCount: 2);
        $runner->run($this->ctx('run-1', '2026-07-10T00:00:00+00:00'));
        $runner->run($this->ctx('run-2', '2026-07-11T00:00:00+00:00'));
        $runner->run($this->ctx('run-3', '2026-07-12T00:00:00+00:00'));

        self::assertArrayNotHasKey('db/run-1.zip', $this->dest->objects);
        self::assertArrayNotHasKey('catalog/run-1.json', $this->dest->objects);
        self::assertArrayHasKey('db/run-2.zip', $this->dest->objects);
        self::assertArrayHasKey('db/run-3.zip', $this->dest->objects);
    }

    public function testRetentionIgnoresOtherSitesEntries(): void
    {
        // Seed a committed backup of another site sharing the same bucket/prefix
        (new RunCatalog($this->dest))->publish(new CatalogEntry(
            runId: 'other-1', startedAt: '2026-07-01T00:00:00+00:00',
            siteUid: 'site-OTHER', craftVersion: '5.8.0', schemaVersion: 's', pluginVersion: '1.0.0',
            dbDriver: 'mysql', objectKey: 'db/other-1.zip', sizeBytes: 1, sha256: str_repeat('a', 64),
        ));
        $this->dest->objects['db/other-1.zip'] = 'x';

        $runner = $this->runner(keepCount: 1);
        $runner->run($this->ctx('run-1', '2026-07-10T00:00:00+00:00'));
        $runner->run($this->ctx('run-2', '2026-07-11T00:00:00+00:00'));

        // This site (site-1) loses run-1 at keepCount=1, but other sites are untouched
        self::assertArrayNotHasKey('db/run-1.zip', $this->dest->objects);
        self::assertArrayHasKey('db/run-2.zip', $this->dest->objects);
        self::assertArrayHasKey('db/other-1.zip', $this->dest->objects);
        self::assertArrayHasKey('catalog/other-1.json', $this->dest->objects);
    }

    public function testNotifyFailureDoesNotFlipCommittedBackup(): void
    {
        $this->slackHttp->fail = true;
        $runner = new BackupRunner(
            dumper: $this->dumper, archiver: new ArchiveBuilder(), dest: $this->dest,
            catalog: new RunCatalog($this->dest), retention: new RetentionPolicy('plugin', 3),
            lock: $this->lock(),
            clock: new FakeClock(),
            notifiers: [new SlackNotifier($this->slackHttp, 'https://hooks.example/x')],
            heartbeat: null,
            preflight: new PreflightChecks($this->workDir, 1, fn() => PHP_INT_MAX),
        );
        $ctx = new RunContext(
            runId: 'run-1', startedAt: '2026-07-12T00:00:00+00:00', siteUid: 'site-1',
            craftVersion: '5.8.0', schemaVersion: 's', pluginVersion: '1.0.0',
            dbDriver: 'mysql', workDir: $this->workDir, notifyOnSuccess: true,
        );
        $record = $runner->run($ctx);
        self::assertTrue($record->isCommitted());
        self::assertFalse($record->sideEffects()['notify:slack']['ok']);
    }

    public function testLockIsReleasedBeforeNotificationsRun(): void
    {
        $contender = $this->lock();
        $notifier = new class($contender) implements Notifier {
            public bool $acquired = false;

            public function __construct(private readonly RunLock $lock)
            {
            }

            public function name(): string
            {
                return 'notify:test';
            }

            public function notify(RunSummary $summary): void
            {
                $this->lock->acquire('notification-contender');
                $this->acquired = true;
                $this->lock->release('notification-contender');
            }
        };
        $runner = new BackupRunner(
            dumper: $this->dumper,
            archiver: new ArchiveBuilder(),
            dest: $this->dest,
            catalog: new RunCatalog($this->dest),
            retention: new RetentionPolicy('plugin', 3),
            lock: $this->lock(),
            clock: new FakeClock(),
            notifiers: [$notifier],
            heartbeat: null,
            preflight: new PreflightChecks($this->workDir, 1, fn() => PHP_INT_MAX),
        );
        $ctx = new RunContext(
            runId: 'run-1', startedAt: '2026-07-12T00:00:00+00:00', siteUid: 'site-1',
            craftVersion: '5.8.0', schemaVersion: 's', pluginVersion: '1.0.0',
            dbDriver: 'mysql', workDir: $this->workDir, notifyOnSuccess: true,
        );

        $runner->run($ctx);

        self::assertTrue($notifier->acquired);
    }

    public function testUnavailableLockFailsBeforeDumpOrUploadAndNotifies(): void
    {
        $holder = $this->lock();
        $holder->acquire('manual-run');
        $dumper = new class implements DatabaseDumper {
            public bool $called = false;

            public function dump(string $targetDir): string
            {
                $this->called = true;
                throw new \RuntimeException('Dump must not run while the lock is unavailable.');
            }
        };
        $runner = new BackupRunner(
            dumper: $dumper,
            archiver: new ArchiveBuilder(),
            dest: $this->dest,
            catalog: new RunCatalog($this->dest),
            retention: new RetentionPolicy('plugin', 3),
            lock: $this->lock(),
            clock: new FakeClock(),
            notifiers: [new SlackNotifier($this->slackHttp, 'https://hooks.example/x')],
            heartbeat: null,
            preflight: new PreflightChecks($this->workDir, 1, fn() => PHP_INT_MAX),
        );

        try {
            $record = $runner->run($this->ctx('run-1'));
        } finally {
            $holder->release('manual-run');
        }

        self::assertFalse($record->isCommitted());
        self::assertStringContainsString('Lock unavailable', (string)$record->failureReason());
        self::assertFalse($dumper->called);
        self::assertSame([], $this->dest->objects);
        self::assertNotEmpty($this->slackHttp->calls);
        self::assertStringNotContainsString($this->workDir, (string)$this->slackHttp->calls[0]['body']);
        self::assertStringNotContainsString('lsof', (string)$this->slackHttp->calls[0]['body']);
    }
}
