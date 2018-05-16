<?php
/**
 * TwitterSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the Twitter API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\twittersync\tasks;

use boxhead\twittersync\TwitterSync;

use Craft;
use craft\base\Task;

/**
 * @author    Boxhead
 * @package   TwitterSync
 * @since     1.0.0
 */
class TwitterSyncTask extends Task
{
    // Public Properties
    // =========================================================================

    // Private Properties
    // =========================================================================
    private $_tweetsToUpdate = [];
    private $_localTweetData;

    // Public Methods
    // =========================================================================

    /**
     * Returns the total number of steps for this task.
     *
     * @return int The total number of steps for this task
     */
    public function getTotalSteps(): int
    {
        Craft::Info('Update Tweets: Get Total Steps', __METHOD__);

        // Pass false to get all small groups
        // Limited to most recent 1000
        $this->_localTweetData = TwitterSync::$plugin->twitterSyncService->getLocalData(1000);

        if (! $this->_localTweetData) {
            Craft::Info('Update Tweets: No local data to work with', __METHOD__);
        }

        foreach ($this->_localTweetData['tweets'] as $groupId => $entryId) {
            $this->_tweetsToUpdate[] = $entryId;
        }

        Craft::Info('Update Tweets - Total Steps: ' . count($this->_tweetsToUpdate), __METHOD__);

        return count($this->_tweetsToUpdate);
    }

    /**
     * Runs a task step.
     *
     * @param int $step The step to run
     *
     * @return bool|string True if the step was successful, false or an error message if not
     */
    public function runStep(int $step)
    {
        Craft::Info('Update Tweets: Running Step ' . $step, __METHOD__);

        $id = $this->_tweetsToUpdate[$step];

        // Update existing DB entry
        TwitterSync::$plugin->twitterSyncService->updateEntry($id);

        return true;
    }


    /**
     * Returns the default description for this task.
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Update local Tweet data';
    }


    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        return Craft::t('twitter-sync', 'TwitterSyncTask');
    }
}
