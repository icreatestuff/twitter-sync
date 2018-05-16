<?php
/**
 * TwitterSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the Twitter API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\twittersync\controllers;

use boxhead\twittersync\TwitterSync;
use boxhead\twittersync\tasks\TwitterSyncTask as TwitterSyncTaskTask;

use Craft;
use craft\web\Controller;

/**
 * @author    Boxhead
 * @package   TwitterSync
 * @since     1.0.0
 */
class DefaultController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['sync-with-remote', 'update-local-data'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's SyncWithRemote action URL,
     * e.g.: actions/twitter-sync/sync-with-remote
     *
     * @return mixed
     */
    public function actionSyncWithRemote() {
        TwitterSync::$plugin->twitterSyncService->sync();

        $result = 'Syncing remote Twitter data';

        return $result;
    }

    /**
     * Handle a request going to our plugin's actionUpdateLocalData URL,
     * e.g.: actions/twitter-sync/default/update-local-data
     *
     * @return mixed
     */
    public function actionUpdateLocalData() {
        $tasks = Craft::$app->getTasks();

        if (!$tasks->areTasksPending(TwitterSyncTaskTask::class)) {
            $tasks->createTask(TwitterSyncTaskTask::class);
        }

        $result = 'Updating Local Twitter Data';

        return $result;
    }
}
