<?php
/**
 * TwitterSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the Twitter API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\twittersync\services;

use boxhead\twittersync\TwitterSync;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Category;
use craft\helpers\ElementHelper;
use craft\helpers\DateTimeHelper;
use Abraham\TwitterOauth\TwitterOauth;

/**
 * TwitterSyncService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Boxhead
 * @package   TwitterSync
 * @since     1.0.0
 */
class TwitterSyncService extends Component
{
    private $settings;
    private $remoteData;
    private $localData;
    private $connection;

    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     TwitterSync::$plugin->TwitterSyncService->sync()
     *
     * @return mixed
     */
    public function sync()
    {
        $this->settings = TwitterSync::$plugin->getSettings();

        // Check for all required settings
        $this->checkSettings();

        // Setup the Twitter connection
        $this->createConnection();

        // Get local twitter entry data
        $this->localData = $this->getLocalData();

        // Request & sync data from the API
        $this->remoteData = $this->getAPIData();

        // Determine which entries we shouldn't have by id
        $removedIds = array_diff($this->localData['ids'], $this->remoteData['ids']);

        // If we have local data that doesn't match with anything from remote we should close the local entry
        foreach ($removedIds as $id) {
            $this->closeEntry($this->localData['tweets'][$id]);
        }

        Craft::info('TwitterSync: Finished', __METHOD__);

        return;
    }


    // Private Methods
    // =========================================================================

    private function dd($data)
    {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
        die();
    }


    private function checkSettings()
    {
        // Check our Plugin's settings for the apiKey
        if ($this->settings->apiKey === null) {
            Craft::error('TwitterSync: No API Key provided in settings', __METHOD__);

            return false;
        }

        // Check our Plugin's settings for the apiSecret
        if ($this->settings->apiSecret === null) {
            Craft::error('TwitterSync: No API Secret provided in settings', __METHOD__);

            return false;
        }

        // Check our Plugin's settings for the screenNames
        if ($this->settings->screenNames === null) {
            Craft::error('TwitterSync: No Twitter Screen Names/Usernames provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->sectionId) {
            Craft::error('TwitterSync: No Section ID provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->entryTypeId) {
            Craft::error('TwitterSync: No Entry Type ID provided in settings', __METHOD__);

            return false;
        }
    }

    private function getAPIData()
    {
        Craft::info('TwitterSync: Begin sync with API', __METHOD__);

        /*
         * Loop over all user Ids and get their tweets
         */

        $tweets = [];
        $screenNames= explode(',', $this->settings->screenNames);

        // Request the tweets for each user specified in the settings
        foreach ($screenNames as $screenName) {
            $params = [
                'screen_name'       => trim($screenName),
                'exclude_replies'   => true,
                'include_rts'       => false,
                'count'             => 200
            ];

            do {
                // Call for more tweets
                $result = $this->connection->get('statuses/user_timeline', $params);

                // If we were paginating, the first result (the tweet with id max_id), is a duplicate. Unset
                if (isset($params['max_id'])) {
                    unset($result[0]);
                }

                // Merge the actual Tweets content into our array
                $tweets = array_merge($tweets, $result);

                // Set the max id for the next call
                if (isset($tweets[count($tweets) - 1])) {
                    $params['max_id'] = $tweets[count($tweets) - 1]->id;
                }

            } while (count($result));
        }
    
        Craft::info('TwitterSync: Finished syncing remote data', __METHOD__);

        // Return all tweet ids 
        $data = [
            'ids'		=>	[],
			'tweets'	=>	[]
        ];
        
        // Loop over tweets and setup our remote data collection
		foreach ($tweets as $tweet) {
			// Get the id
            $tweetId = $tweet->id_str;
            
			// Add a reference to the screen name (in the same format twitter uses) for later use
            $tweet->screen_name = $tweet->user->screen_name;
            
			// Add this id to our array
            $data['ids'][] = $tweetId;
            
			// Add this tweet to our array, using the id as the key
			$data['tweets'][$tweetId] = $tweet;
		}

        return $data;
    }


    private function getLocalData()
    {
        Craft::info('TwitterSync: Query for all Twitter entries', __METHOD__);

        // Create a Craft Element Criteria Model
        $query = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->limit(null)
            ->status(null)
            ->all();

        $data = array(
            'ids'      =>  [],
            'tweets'   =>  []
        );

        // For each entry
        foreach ($query as $entry)
        {
            $tweetId = "";

            // Get the id of this tweet
            if (isset($entry->tweetId))
            {
                $tweetId = $entry->tweetId;
            }

            // Add this id to our array
            $data['ids'][] = $tweetId;

            // Add this entry id to our array, using the tweet id as the key for reference
            $data['tweets'][$tweetId] = $entry->id;
        }

        Craft::info('TwitterSync: Return local data for comparison', __METHOD__);

        return $data;
    }


    private function createEntry($data)
    {
        // Create a new instance of the Craft Entry Model
        $entry = new Entry();

        // Set the section id
        $entry->sectionId = $this->settings->sectionId;

        // Set the entry type
        $entry->typeId = $this->settings->entryTypeId;

        // Set the author as super admin
        $entry->authorId = 1;

        $this->saveFieldData($entry, $data);
    }

    private function updateEntry($entryId, $data)
    {
        // Create a new instance of the Craft Entry Model
        $entry = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->id($entryId)
            ->status(null)
            ->one();

        $this->saveFieldData($entry, $data);
    }

    private function closeEntry($entryId)
    {
        // Create a new instance of the Craft Entry Model
        $entry = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->id($entryId)
            ->status(null)
            ->one();

        $entry->enabled = false;

        // Re-save the entry
        Craft::$app->elements->saveElement($entry);
    }

    private function saveFieldData($entry, $data)
    {
        // Set all tweets to enabled/open by default unless previously closed
        $entry->enabled = (!$entry->enabled) ? false : true;

        // Set the title
        $entry->title = $data->screen_name . ' - ' . $data->id_str;

        // Set the other content
        $entry->setFieldValues([
            'tweetId'           => $data->id_str,
            'tweetText'         => $this->formatTweetText($data->text),
            'tweetUserId'		=> $data->user->id_str,
            'tweetScreenName'   => $data->screen_name,
            'tweetPageUrl'      => $this->constructTweetPageUrl($tweet)
        ]);

        // Save the entry!
        if (!Craft::$app->elements->saveElement($entry)) {
            Craft::error('TwitterSync: Couldn’t save the entry "' . $entry->title . '"', __METHOD__);

            return false;
        }

        // Set the postdate to the publishedAt date
        $entry->postDate = DateTimeHelper::toDateTime(strtotime($data->created_at));

        // Re-save the entry
        Craft::$app->elements->saveElement($entry);
    }

    /**
     * Returns a Twitter OAuth API connection
     */
    private function createConnection() {
        $this->connection = new TwitterOAuth($this->settings->apiKey, $this->settings->apiSecret);
    }

    private function formatTweetText($text) {
        // Escape any quotes
        $text = htmlspecialchars($text, ENT_QUOTES);
        
        // If there are any user mentions in the tweet, insert the relevant html here
        if (!empty($tweet->entities->user_mentions))
        {
            foreach ($tweet->entities->user_mentions as $user_mention)
            {
                $screen_name = $user_mention->screen_name;
                $text = str_replace('@' . $screen_name, '<a href="http://twitter.com/' . $screen_name . '" target="_blank">' . '@' . $screen_name . '</a>', $text);
            }
        }

        // If there are any links in the tweet, insert the relevant html here
        if (!empty($tweet->entities->urls))
        {
            foreach ($tweet->entities->urls as $link) {
                $url = $link->url;
                $expanded_url = $link->expanded_url;
                $text = str_replace($url, '<a href="' . $expanded_url . '" target="_blank">' . $url . '</a>', $text);
            }
        }

        // If there are any media contents, these won't show here and will just output a messy link - remove them
        if (!empty($tweet->entities->media))
        {
            foreach($tweet->entities->media as $media)
            {
                $url = $media->url;
                $text = str_replace($url, '', $text);
            }
        }

        return $text;
    }

    private function constructTweetPageUrl($tweet) {
		return 'https://twitter.com/' . $tweet->screen_name . '/status/' . $tweet->id;
	}
}
