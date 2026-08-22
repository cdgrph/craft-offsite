<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * DB backup pipeline:
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

    public function run(RunContext $ctx, ?\Closure $onProgress = null): RunRecord
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
            // Commit-aware resume for redelivered attempts (fixed runId): the
            // predecessor died after catalog publish, so the backup is committed
            // and only the epilogue (notify/heartbeat/cache) is owed. Checked
            // while holding the lock, which a publish also requires, so the
            // catalog cannot change under this run once the check has passed.
            // A check failure falls through to the shared catch, so failure
            // notifications still fire.
            if ($this->catalog->has($ctx->runId)) {
                $this->progress('finalize', 0.90, 'Finalizing previous backup', $onProgress);
                // Report the committed backup's own timestamp and size rather than
                // this attempt's. The local run cache drives the "backups are
                // overdue" indicator, so dating a resume as if it were a fresh dump
                // would hide a stale backup for as long as redelivery took.
                $entry = $this->catalog->get($ctx->runId);
                $record = new RunRecord($ctx->runId, $entry->startedAt);
                $archiveSizeBytes = $entry->sizeBytes;
                $record->markUploading();
                $record->markCommitted();
                $record->recordSideEffect('resumed-from-catalog', true);
                return $record;
            }

            $this->preflight->run();
            $record->markUploading();

            $this->progress('dump', 0.10, 'Dumping database', $onProgress);
            $dump = $this->dumper->dump($ctx->workDir);
            $tmpFiles[] = $dump->path;

            $zipPath = $ctx->workDir . '/' . $ctx->runId . '.zip';
            // Registered before the zip is created: a partially written zip is also cleaned up on failure
            $tmpFiles[] = $zipPath;
            $this->progress('archive', 0.35, 'Creating archive', $onProgress);
            // A custom-format dump is already compressed; storing it avoids burning
            // CPU on a second (useless) deflate pass
            $archive = $this->archiver->zipFile(
                $dump->path,
                $zipPath,
                $dump->format->entryName(),
                compress: !$dump->format->preCompressed(),
            );

            $objectKey = $ctx->objectKeyPrefix . $ctx->runId . '.zip';
            $this->progress('upload', 0.55, 'Uploading backup', $onProgress);
            $archiveSizeBytes = $archive->sizeBytes;
            $this->dest->put($objectKey, $archive->path, $archive->sha256);

            // Catalog publish is the sole evidence of commit
            $this->progress('publish', 0.85, 'Publishing backup', $onProgress);
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
                dumpFormat: $dump->format->value,
            ));
            $record->markCommitted();

            $this->progress('retention', 0.95, 'Applying retention', $onProgress);
            $this->applyRetention($record, $ctx->siteUid);
        } catch (\Throwable $e) {
            if ($record->isCommitted()) {
                // Unexpected exceptions after commit are recorded as side effects (committed is immutable)
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

    private function progress(
        string $phase,
        float $progress,
        string $label,
        ?\Closure $onProgress,
    ): void {
        $this->lock->progress($phase);
        if ($onProgress !== null) {
            try {
                $onProgress($progress, $label);
            } catch (\Throwable) {
                // Progress is display-only and must never change backup correctness.
            }
        }
    }

    private function applyRetention(RunRecord $record, string $siteUid): void
    {
        try {
            // Only this site's entries participate in generation counting, so backups of
            // other sites sharing the same bucket/prefix are never swept up
            $mine = array_values(array_filter(
                $this->catalog->all(),
                fn(CatalogEntry $e) => $e->siteUid === $siteUid,
            ));
            foreach ($this->retention->selectForDeletion($mine) as $old) {
                // Remove the catalog entry first: in the reverse order, a failed catalog
                // delete would leave commit evidence pointing at a nonexistent object.
                // Orphaned objects are picked up safely by prune --orphans
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
        if (isset($record->sideEffects()['resumed-from-catalog'])) {
            // This attempt only finished the bookkeeping an earlier attempt owed, so
            // its elapsed time says nothing about how long the backup took. Report
            // the resume instead, otherwise a seconds-long "duration" next to a full
            // backup size reads as a wildly fast run.
            $details['note'] = 'Resumed a backup an earlier attempt had already committed';
            return $details;
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
