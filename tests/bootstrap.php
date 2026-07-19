<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
// Yii is not composer-autoloadable; validators resolve classes via Yii::createObject
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
// Craft extends Yii; functional tests need it for Plugin instantiation
require __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';
