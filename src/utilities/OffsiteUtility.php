<?php
declare(strict_types=1);

namespace cdgrph\offsite\utilities;

use cdgrph\offsite\engine\SettingsValidator;
use cdgrph\offsite\Plugin;
use cdgrph\offsite\records\RunRow;
use craft\base\Utility;

final class OffsiteUtility extends Utility
{
    /** Hours without a committed backup before the utility warns that backups are overdue. */
    private const WARN_AFTER_HOURS = 48;

    /**
     * Whether backups are overdue, given the seconds elapsed since the last
     * committed backup. Null means no backup has ever been committed.
     */
    public static function isOverdue(?int $staleSec): bool
    {
        return $staleSec === null || $staleSec >= self::WARN_AFTER_HOURS * 3600;
    }

    public static function displayName(): string
    {
        return 'Offsite Backups';
    }

    public static function id(): string
    {
        return 'offsite';
    }

    public static function icon(): ?string
    {
        return 'cloud-arrow-up';
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second ago' : $seconds . ' seconds ago';
        }
        if ($seconds < 3600) {
            $m = (int)floor($seconds / 60);
            return $m === 1 ? '1 minute ago' : $m . ' minutes ago';
        }
        if ($seconds < 86400) {
            $h = (int)floor($seconds / 3600);
            return $h === 1 ? '1 hour ago' : $h . ' hours ago';
        }
        $d = (int)floor($seconds / 86400);
        return $d === 1 ? '1 day ago' : $d . ' days ago';
    }

    public static function contentHtml(): string
    {
        $rows = RunRow::find()->orderBy(['startedAt' => SORT_DESC])->limit(20)->all();
        $lastCommitted = RunRow::find()->where(['backupStatus' => 'committed'])
            ->orderBy(['startedAt' => SORT_DESC])->one();
        $staleSec = null;
        if ($lastCommitted !== null) {
            $json = json_decode((string)$lastCommitted->summaryJson, true);
            $atomDate = $json['startedAt'] ?? $lastCommitted->startedAt;
            $staleSec = max(0, time() - (new \DateTimeImmutable($atomDate))->getTimestamp());
        }
        $resolved = Plugin::getInstance()->getSettings()->resolved();
        $validator = new SettingsValidator();
        $channelWarnings = $validator->warnings($resolved);
        $settingsErrors = $validator->validate($resolved);

        $enrichedRows = array_map(function (RunRow $row): array {
            $data = json_decode((string)$row->summaryJson, true) ?: [];
            $details = $data['details'] ?? [];
            $startedAt = null;
            try {
                $atomDate = $data['startedAt'] ?? null;
                if ($atomDate !== null) {
                    $startedAt = new \DateTimeImmutable($atomDate);
                }
            } catch (\Throwable) {
            }
            return [
                'runId' => $row->runId,
                'startedAt' => $startedAt,
                'backupStatus' => $row->backupStatus,
                'size' => $details['size'] ?? null,
                'duration' => $details['duration'] ?? null,
                'error' => $details['error'] ?? null,
            ];
        }, $rows);

        return \Craft::$app->getView()->renderTemplate('offsite/utility.twig', [
            'rows' => $enrichedRows,
            'lastBackupAgo' => $staleSec !== null ? self::humanDuration($staleSec) : null,
            'warn' => self::isOverdue($staleSec),
            'warnAfterHours' => self::WARN_AFTER_HOURS,
            'channelWarnings' => $channelWarnings,
            'settingsErrors' => $settingsErrors,
        ]);
    }
}
