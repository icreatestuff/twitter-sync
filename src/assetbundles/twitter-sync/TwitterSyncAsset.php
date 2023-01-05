<?php
/**
 * TwitterSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the Twitter API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\twittersync\assetbundles;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * @author    Boxhead
 * @package   TwitterSync
 * @since     1.0.0
 */
class TwitterSyncAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * Initializes the bundle.
     */
    public function init()
    {
        // define the path that your publishable resources live
        $this->sourcePath = "@boxhead/twittersync/assetbundles/twitter-sync/dist";

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        // define the relative path to CSS/JS files that should be registered with the page
        // when this asset bundle is registered
        $this->js = [
            'js/TwitterSync.js',
        ];

        $this->css = [
            'css/TwitterSync.css',
        ];

        parent::init();
    }
}
