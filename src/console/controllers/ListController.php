<?php
declare(strict_types=1);

namespace cdgrph\offsite\console\controllers;

use cdgrph\offsite\adapters\RunnerFactory;
use craft\console\Controller;
use yii\console\ExitCode;

final class ListController extends Controller
{
    /** Lists backups from the REMOTE catalog (source of truth). */
    public function actionIndex(): int
    {
        $factory = new RunnerFactory();
        if ($factory->settingsErrors() !== []) {
            $this->stderr("Offsite settings invalid:\n - " . implode("\n - ", $factory->settingsErrors()) . "\n");
            return ExitCode::CONFIG;
        }
        foreach ($factory->catalog()->all() as $e) {
            $this->stdout(sprintf(
                "%s  %s  %10d bytes  craft %s  %s\n",
                $e->runId, $e->startedAt, $e->sizeBytes, $e->craftVersion, $e->objectKey
            ));
        }
        return ExitCode::OK;
    }
}
