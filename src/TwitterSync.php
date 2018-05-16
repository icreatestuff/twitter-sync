<?php
/**
 * TwitterSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the Twitter API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\twittersync;

use boxhead\twittersync\services\TwitterSyncService as TwitterSyncServiceService;
use boxhead\twittersync\models\Settings;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\web\UrlManager;
use craft\services\Elements;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\models\FieldGroup;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\elements\Entry;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Boxhead
 * @package   TwitterSyncService
 * @since     1.0.0
 *
 * @property  twitterSyncServiceService $twitterSyncService
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class TwitterSyncService extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * TwitterSyncService::$plugin
     *
     * @var TwitterSyncService
     */
    public static $plugin;
    public $hasCpSettings = true;

    // Public Methods
    // =========================================================================
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register our site routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['twitter-sync/sync']   = 'twitter-sync/default/sync-with-remote';
                $event->rules['twitter-sync/update'] = 'twitter-sync/default/update-local-data';
            }
        );

        // Register our elements
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                    $this->buildFieldSectionStructure();
                }
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        Craft::info(
            Craft::t(
                'twitter-sync',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    protected function buildFieldSectionStructure()
    {
        // Create the Tweets field group
        Craft::info('Creating the Tweets Field Group.', __METHOD__);

        $fieldsService = Craft::$app->getFields();

        $fieldGroup = new FieldGroup();
        $fieldGroup->name = 'Tweets';

        if (!$fieldsService->saveGroup($fieldGroup)) {
            Craft::error('Could not save the Tweets field group.', __METHOD__);

            return false;
        }

        $fieldGroupId = $fieldGroup->id;

        // Create the Basic Fields
        Craft::info('Creating the basic Tweet Fields.', __METHOD__);

        $basicFields = [
            [
                'handle'    => 'tweetId',
                'name'      => 'Tweet Id',
                'type'      => 'craft\fields\PlainText'
            ],
            [
                'handle'    => 'tweetText',
                'name'      => 'Tweet Text',
                'type'      => 'craft\fields\PlainText',
                'multiline' => true
            ],
            [
                'handle'    => 'tweetUserId',
                'name'      => 'Tweet User Id',
                'type'      => 'craft\fields\PlainText'
            ],
            [
                'handle'    => 'tweetScreenName',
                'name'      => 'Tweet User Screen Name',
                'type'      => 'craft\fields\PlainText'
            ],
            [
                'handle'    => 'tweetPageUrl',
                'name'      => 'Tweet URL',
                'type'      => 'craft\fields\PlainText'
            ]
        ];

        $tweetEntryLayoutIds = [];

        foreach ($basicFields as $basicField) {
            Craft::info('Creating the ' . $basicField['name'] . ' field.', __METHOD__);

            $field = $fieldsService->createField([
                'groupId' => $fieldGroupId,
                'name' => $basicField['name'],
                'handle' => $basicField['handle'],
                'type' => $basicField['type']
            ]);

            if (!$fieldsService->saveField($field)) {
                Craft::error('Could not save the ' . $basicField['name'] . ' field.', __METHOD__);

                return false;
            }

            $tweetEntryLayoutIds[] = $field->id;
        }

        // Create the Tweet Field Layout
        Craft::info('Creating the Tweets Field Layout.', __METHOD__);

        $tweetsEntryLayout = $fieldsService->assembleLayout(['Tweets' => $tweetEntryLayoutIds], []);

        if (!$tweetsEntryLayout) {
            Craft::error('Could not create the Tweets Field Layout', __METHOD__);

            return false;
        }

        $tweetsEntryLayout->type = Entry::class;

        // Create the Tweets Channel
        Craft::info('Creating the Tweets Channel.', __METHOD__);

        $tweetsChannelSection                   = new Section();
        $tweetsChannelSection->name             = 'Tweets';
        $tweetsChannelSection->handle           = 'tweets';
        $tweetsChannelSection->type             = Section::TYPE_CHANNEL;
        $tweetsChannelSection->enableVersioning = false;

        // Site-specific settings
        $allSiteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings = new Section_SiteSettings();
            $siteSettings->siteId = $site->id;
            $siteSettings->uriFormat = null;
            $siteSettings->template = null;
            $siteSettings->enabledByDefault = true;
            $siteSettings->hasUrls = false;

            $allSiteSettings[$site->id] = $siteSettings;
        }

        $tweetsChannelSection->setSiteSettings($allSiteSettings);

        $sectionsService = Craft::$app->getSections();

        if (!$sectionsService->saveSection($tweetsChannelSection)) {
            Craft::error('Could not create the Tweets Channel.', __METHOD__);

            return false;
        }

        // Get the array of entry types for our new section
        $tweetsEntryTypes = $sectionsService->getEntryTypesBySectionId($tweetsChannelSection->id);

        // There will only be one so get that
        $tweetsEntryTypes = $tweetsEntryTypes[0];
        $tweetsEntryTypes->setFieldLayout($tweetsEntryLayout);

        if (!$sectionsService->saveEntryType($tweetsEntryTypes)) {
            Craft::error('Could not update the Tweets Channel Entry Type.', __METHOD__);

            return false;
        }

        // Save the settings based on the section and entry type we just created
        Craft::$app->getPlugins()->savePluginSettings($this, [
            'sectionId'     => $tweetsChannelSection->id,
            'entryTypeId'   => $tweetsEntryTypes->id
        ]);
    }



    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }


    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'twitter-sync/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }
}
