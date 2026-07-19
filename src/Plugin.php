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
    public bool $hasCpSettings = true; // Operational settings only — secrets and infrastructure stay in config/offsite.php (Settings::CP_FIELDS)

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

    protected function settingsHtml(): ?string
    {
        // Keys present in config/offsite.php always override CP-saved values;
        // surface that per-field so a "successful" save is never silently inert.
        $fileConfig = \Craft::$app->getConfig()->getConfigFromFile('offsite');
        $fileConfig = \is_array($fileConfig) ? $fileConfig : [];

        return \Craft::$app->getView()->renderTemplate('offsite/_settings.twig', [
            'settings' => $this->getSettings(),
            'overriddenFields' => array_values(array_intersect(Settings::CP_FIELDS, array_keys($fileConfig))),
        ]);
    }
}
