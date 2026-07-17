<?php
declare(strict_types=1);

namespace cdgrph\offsite\controllers;

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
        // If the parent is SIGKILLed at TTR, the inherited mysqldump is orphaned while
        // holding the lock and blocks every later run, so declare a generous explicit
        // limit (see RunLock).
        Queue::push(new RunBackupJob(), ttr: 86_400);
        \Craft::$app->getSession()->setNotice('Backup job queued.');
        return $this->redirectToPostedUrl();
    }
}
