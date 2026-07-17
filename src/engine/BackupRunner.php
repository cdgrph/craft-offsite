<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * DB backup pipeline (spec §4):
 * preflight → dump → zip+sha256 → upload (verified) → catalog publish (= the commit)
 * → retention → notify → heartbeat. Side effects never flip a committed backup.
 */
final class BackupRunner
{
    /** @param list<Notifier> $notifiers */
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly ArchiveBuilder $archiver,
        private readonly Destination $dest,
        private readonly RunCatalog $catalog,
        private readonly RetentionPolicy $retention,
        private readonly RunLock $lock,
        private readonly Clock $clock,
        private readonly array $notifiers,
        private readonly ?HeartbeatPinger $heartbeat,
        private readonly PreflightChecks $preflight,
    ) {
    }

    public function run(RunContext $ctx): RunRecord
    {
        $record = new RunRecord($ctx->runId, $ctx->startedAt);
        $owner = 'backup:' . $ctx->runId;
        $tmpFiles = [];
        $archiveSizeBytes = null;

        try {
            $this->lock->acquire($owner);
        } catch (LockUnavailableException $e) {
            $record->markFailed('Lock unavailable: ' . $e->getMessage());
            $this->finish($record, $ctx);
            return $record;
        }

        try {
            $this->preflight->run();
            $record->markUploading();

            $this->lock->progress('dump');
            $dumpPath = $this->dumper->dump($ctx->workDir);
            $tmpFiles[] = $dumpPath;

            $zipPath = $ctx->workDir . '/' . $ctx->runId . '.zip';
            // Registered before the zip is created: a partially written zip is also cleaned up on failure (Codex review S12)
            $tmpFiles[] = $zipPath;
            $this->lock->progress('archive');
            $archive = $this->archiver->zipFile($dumpPath, $zipPath, 'backup.sql');

            $objectKey = $ctx->objectKeyPrefix . $ctx->runId . '.zip';
            $this->lock->progress('upload');
            $archiveSizeBytes = $archive->sizeBytes;
            $this->dest->put($objectKey, $archive->path, $archive->sha256);

            // Catalog publish is the sole evidence of commit (spec §4)
            $this->lock->progress('publish');
            $this->catalog->publish(new CatalogEntry(
                runId: $ctx->runId,
                startedAt: $ctx->startedAt,
                siteUid: $ctx->siteUid,
                craftVersion: $ctx->craftVersion,
                schemaVersion: $ctx->schemaVersion,
                pluginVersion: $ctx->pluginVersion,
                dbDriver: $ctx->dbDriver,
                objectKey: $objectKey,
                sizeBytes: $archive->sizeBytes,
                sha256: $archive->sha256,
            ));
            $record->markCommitted();

            $this->lock->progress('retention');
            $this->applyRetention($record, $ctx->siteUid);
        } catch (\Throwable $e) {
            if ($record->isCommitted()) {
                // Unexpected exceptions after commit are recorded as side effects (committed is immutable — spec §5-1)
                $record->recordSideEffect('post-commit', false, $e->getMessage());
            } else {
                $record->markFailed($e->getMessage());
            }
        } finally {
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
            try {
                $this->lock->release($owner);
            } catch (\Throwable $e) {
                // RunLock always closes the fd in its finally block, even when state/unlock fail.
                // A release error never overturns the result of a committed run.
                $record->recordSideEffect('lock-release', false, $e->getMessage());
            }
            $this->finish($record, $ctx, $archiveSizeBytes);
        }

        return $record;
    }

    private function applyRetention(RunRecord $record, string $siteUid): void
    {
        try {
            // Only this site's entries participate in generation counting, so backups of
            // other sites sharing the same bucket/prefix are never swept up (Codex review B7)
            $mine = array_values(array_filter(
                $this->catalog->all(),
                fn(CatalogEntry $e) => $e->siteUid === $siteUid,
            ));
            foreach ($this->retention->selectForDeletion($mine) as $old) {
                // Remove the catalog entry first: in the reverse order, a failed catalog
                // delete would leave commit evidence pointing at a nonexistent object.
                // Orphaned objects are picked up safely by prune --orphans (Codex review S10)
                $this->catalog->remove($old->runId);
                $this->dest->delete($old->objectKey);
            }
            $record->recordSideEffect('retention', true);
        } catch (\Throwable $e) {
            $record->recordSideEffect('retention', false, $e->getMessage());
        }
    }

    /** @return array<string, string> */
    private static function buildDetails(RunRecord $record, RunContext $ctx, ?int $sizeBytes): array
    {
        $details = [];
        if ($ctx->siteName !== '') {
            $details['site'] = $ctx->siteName;
        }
        $details['status'] = $record->backupStatus();
        if ($sizeBytes !== null) {
            $details['size'] = self::formatBytes($sizeBytes);
        }
        $elapsed = (new \DateTimeImmutable())->getTimestamp()
                 - (new \DateTimeImmutable($ctx->startedAt))->getTimestamp();
        if ($elapsed >= 0) {
            $details['duration'] = $elapsed . 's';
        }
        return $details;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    private function finish(RunRecord $record, RunContext $ctx, ?int $sizeBytes = null): void
    {
        $ok = $record->isCommitted();
        $details = self::buildDetails($record, $ctx, $sizeBytes);
        if (!$ok && $record->failureReason() !== null) {
            $details['error'] = ErrorSummarizer::summarize($record->failureReason());
        }
        $record->setDetails($details);
        $summary = new RunSummary(
            runId: $record->runId,
            ok: $ok,
            message: $ok ? 'Backup succeeded' : 'Backup failed',
            details: $details,
        );

        if (!$ok || $ctx->notifyOnSuccess) {
            foreach ($this->notifiers as $notifier) {
                try {
                    $notifier->notify($summary);
                    $record->recordSideEffect($notifier->name(), true);
                } catch (\Throwable $e) {
                    $record->recordSideEffect($notifier->name(), false, $e->getMessage());
                }
            }
        }

        if ($this->heartbeat !== null) {
            try {
                $ok ? $this->heartbeat->success() : $this->heartbeat->failure();
                $record->recordSideEffect('heartbeat', true);
            } catch (\Throwable $e) {
                $record->recordSideEffect('heartbeat', false, $e->getMessage());
            }
        }
    }
}
