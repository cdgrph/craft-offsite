<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\RunnerFactory;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

final class VerifyController extends Controller
{
    /** Verifies every catalog entry has its object with the expected size. */
    public function actionIndex(): int
    {
        $factory = new RunnerFactory();
        if ($factory->settingsErrors() !== []) {
            $this->stderr("Offsite settings invalid:\n - " . implode("\n - ", $factory->settingsErrors()) . "\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $dest = $factory->destination();
        $bad = 0;
        $objects = [];
        foreach ($dest->listByPrefix('db/') as $obj) {
            $objects[$obj['key']] = $obj['size'];
        }
        foreach ($factory->catalog()->all() as $e) {
            if (!isset($objects[$e->objectKey])) {
                $this->stderr("MISSING: {$e->runId} expects {$e->objectKey}\n", Console::FG_RED);
                $bad++;
            } elseif ($objects[$e->objectKey] !== $e->sizeBytes) {
                $this->stderr("SIZE MISMATCH: {$e->objectKey} remote={$objects[$e->objectKey]} catalog={$e->sizeBytes}\n", Console::FG_RED);
                $bad++;
            } else {
                $this->stdout("ok: {$e->runId}\n", Console::FG_GREEN);
            }
        }
        return $bad === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
