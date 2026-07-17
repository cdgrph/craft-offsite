<?php
declare(strict_types=1);

namespace cdgrph\offsite;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use cdgrph\offsite\models\Settings;
use cdgrph\offsite\utilities\OffsiteUtility;
use yii\base\Event;

/**
 * Offsite — scheduled off-site DB backups for Craft CMS.
 */
final class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false; // Settings live in config/offsite.php only (secrets are never stored in the DB)

    public function init(): void
    {
        parent::init();
        $this->controllerNamespace = \Craft::$app instanceof \craft\console\Application
            ? 'cdgrph\\offsite\\console\\controllers'
            : 'cdgrph\\offsite\\controllers';

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = OffsiteUtility::class;
            }
        );

        Event::on(
            \craft\web\View::class,
            \craft\web\View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function (\craft\events\RegisterTemplateRootsEvent $e): void {
                $e->roots['offsite'] = \dirname(__DIR__) . '/templates';
            }
        );
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
