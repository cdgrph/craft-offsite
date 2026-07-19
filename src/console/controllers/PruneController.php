<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\engine\LockUnavailableException;
use cdgrph\offsite\engine\OrphanPolicy;
use cdgrph\offsite\engine\RetentionPolicy;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

final class PruneController extends Controller
{
    public bool $orphans = false;
    public bool $force = false;
    public int $orphanAgeHours = 24;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['orphans', 'force', 'orphanAgeHours']);
    }

    public function actionIndex(): int
    {
        if ($this->orphanAgeHours < 0) {
            $this->stderr("--orphan-age-hours must be >= 0.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if ($this->orphanAgeHours > 87600) {
            $this->stderr("--orphan-age-hours must be <= 87600.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        // Yii2 may coerce non-numeric or decimal input to 0, so orphan deletion at
        // 0 hours requires an explicit --force to keep typos from disabling the age guard
        if ($this->orphans && $this->orphanAgeHours === 0 && !$this->force) {
            $this->stderr("--orphan-age-hours=0 disables the age guard and may delete in-flight uploads. Use --force to proceed. Non-numeric or decimal values can be coerced to 0; pass a whole number of hours.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $orphanAgeSeconds = $this->orphanAgeHours * 3600;

        $factory = new RunnerFactory();
        $settings = $factory->settingsErrors();
        if ($settings !== []) {
            $this->stderr("Settings invalid.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        /** @var \cdgrph\offsite\models\Settings $s */
        $s = \cdgrph\offsite\Plugin::getInstance()->getSettings();
        // The lifecycle-mode contract is "the plugin never deletes" — --orphans is no
        // exception (Codex review S15). Deletion is fully delegated to bucket lifecycle rules
        if ($s->retentionMode === RetentionPolicy::MODE_LIFECYCLE) {
            $this->stderr("Retention mode is 'lifecycle': the plugin never deletes objects. Configure bucket lifecycle rules instead.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if ($this->orphans && $this->orphanAgeHours === 0) {
            $this->stderr("Warning: age guard is disabled — every uncataloged object is eligible for deletion.\n", Console::FG_YELLOW);
        }
        if ($this->orphans && $this->orphanAgeHours < 24) {
            $this->stderr("Warning: the orphan age guard must exceed the longest upload and verification time. It protects in-flight uploads from other sites sharing this prefix because the lock does not work across sites.\n", Console::FG_YELLOW);
        }

        $lock = $factory->lock();
        $owner = 'prune:' . bin2hex(random_bytes(4));
        try {
            $lock->acquire($owner);
        } catch (LockUnavailableException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        try {
            $lock->progress('retention');
            $catalog = $factory->catalog();
            $dest = $factory->destination();
            $siteUid = (string)\Craft::$app->getInfo()->uid;
            $policy = new RetentionPolicy($s->retentionMode, (int)$s->retentionKeepCount);
            // Never sweep up other sites' generations (Codex review B7). Delete the
            // catalog entry first (Codex review S10)
            $mine = array_values(array_filter($catalog->all(), fn($e) => $e->siteUid === $siteUid));
            foreach ($policy->selectForDeletion($mine) as $old) {
                $this->stdout("Deleting {$old->runId} ({$old->objectKey})\n");
                $catalog->remove($old->runId);
                $dest->delete($old->objectKey);
            }
            if ($this->orphans) {
                $lock->progress('orphan-scan');
                // Uncataloged db/ objects are leftovers of aborted runs or in-flight uploads, so clean them up behind the age guard
                $objects = $dest->listByPrefix('db/');
                // Reading the catalog after the listing protects entries published in
                // between by identity. Use all sites' entries (not `$mine`) so committed
                // backups of other sites sharing the prefix are protected too
                $known = array_map(fn($e) => $e->objectKey, $catalog->all());
                $orphanPolicy = new OrphanPolicy(minAgeSeconds: $orphanAgeSeconds);
                $selection = $orphanPolicy->select($objects, $known, time());
                $this->stdout("Orphan scan covers the entire db/ area under the configured keyPrefix and cannot be filtered by siteUid.\n");
                if ($this->orphanAgeHours > 0) {
                    $this->stdout("Skipped {$selection['skipped']} uncataloged object(s) younger than {$this->orphanAgeHours}h (possible in-flight uploads).\n");
                }
                foreach ($selection['orphans'] as $key) {
                    $this->stdout("Deleting orphan {$key}\n", Console::FG_YELLOW);
                    $dest->delete($key);
                }
            }
            return ExitCode::OK;
        } finally {
            try {
                $lock->release($owner);
            } catch (\Throwable $e) {
                $this->stderr("Lock release failed: {$e->getMessage()}\n", Console::FG_RED);
            }
        }
    }
}
