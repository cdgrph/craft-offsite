<?php
declare(strict_types=1);

namespace cdgrph\offsite\records;

use cdgrph\offsite\engine\RunRecord;
use craft\db\ActiveRecord;

/**
 * Local run cache — UI display only. The remote RunCatalog is the source of truth.
 */
final class RunRow extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%offsite_runs}}';
    }

    /**
     * Monotonic cache rule: a committed row is never demoted by a later
     * non-committed attempt (a redelivered attempt can fail on the lock after
     * the original run already committed). Kept pure for testing without a DB.
     */
    public static function shouldOverwrite(?string $existingStatus, string $newStatus): bool
    {
        return $existingStatus !== RunRecord::COMMITTED || $newStatus === RunRecord::COMMITTED;
    }

    /**
     * Upsert keyed by the unique runId column. Retries once when a concurrent
     * attempt wins the insert race. Never throws — a cache write failure must
     * not taint the outcome of a committed backup; the caller decides how to
     * surface the returned error message.
     */
    public static function upsertFromRecord(RunRecord $record): ?string
    {
        try {
            return self::tryUpsert($record);
        } catch (\Throwable) {
            // Likely a unique-violation race with a concurrent attempt: the row
            // now exists, so reload and re-apply the monotonic rule once.
            try {
                return self::tryUpsert($record);
            } catch (\Throwable $retry) {
                return $retry->getMessage();
            }
        }
    }

    private static function tryUpsert(RunRecord $record): ?string
    {
        $row = self::findOne(['runId' => $record->runId]) ?? new self();
        if (!self::shouldOverwrite($row->backupStatus, $record->backupStatus())) {
            return null;
        }
        $row->runId = $record->runId;
        $row->startedAt = date('Y-m-d H:i:s', strtotime($record->startedAt));
        $row->backupStatus = $record->backupStatus();
        $row->summaryJson = json_encode($record->toArray());
        $row->save(false);
        return null;
    }
}
