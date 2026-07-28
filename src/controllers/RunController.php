<?php
declare(strict_types=1);

namespace cdgrph\offsite\controllers;

use cdgrph\offsite\adapters\RunnerFactory;
use cdgrph\offsite\jobs\RunBackupJob;
use craft\helpers\Queue;
use craft\web\Controller;
use yii\web\Response;

final class RunController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionEnqueue(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false); // Admins only. allowAdminChanges not required (no settings are changed)
        // runId is fixed at push time so a redelivered attempt resumes the same
        // run instead of creating a duplicate backup. TTR is declared by the job
        // itself (RunBackupJob::getTtr()).
        Queue::push(new RunBackupJob(['runId' => RunnerFactory::newRunId()]));
        \Craft::$app->getSession()->setNotice('Backup job queued.');
        return $this->redirectToPostedUrl();
    }
}
