<?php
namespace newznab\processing\tv;

use \libs\JPinkney\TVMaze\Client;
use newznab\ReleaseImage;

/**
 * Class TVMaze
 *
 * Process information retrieved from the TVMaze API.
 */
class TVMaze extends TV
{
	const MATCH_PROBABILITY = 75;

	/**
	 * Client for TVMaze API
	 *
	 * @var \libs\JPinkney\TVMaze\Client
	 */
	public $client;

	/**
	 * @var string The URL for the medium sized image for poster
	 */
	private $posterUrl;

	/**
	 * Construct. Instanciate TVMaze Client Class
	 *
	 * @param array $options Class instances.
	 *
	 * @access public
	 */
	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$this->client = new Client();
	}

	/**
	 * Fetch banner from site.
	 *
	 * @param $videoId
	 * @param $siteID
	 *
	 * @return bool
	 */
	public function getBanner($videoId, $siteID)
	{
		return false;
	}

	/**
	 * Main processing director function for TVMaze
	 * Calls work query function and initiates processing
	 *
	 * @param            $groupID
	 * @param            $guidChar
	 * @param            $processTV
	 * @param bool|false $local
	 */
	public function processTVMaze ($groupID, $guidChar, $processTV, $local = false)
	{
		$res = $this->getTvReleases($groupID, $guidChar, $processTV, parent::PROCESS_TVMAZE);

		$tvcount = $res->rowCount();

		if ($this->echooutput && $tvcount > 1) {
			echo $this->pdo->log->header("Processing TVMaze lookup for " . number_format($tvcount) . " release(s).");
		}

		if ($res instanceof \Traversable) {
			foreach ($res as $row) {

				$this->posterUrl = '';

				// Clean the show name for better match probability
				$release = $this->parseNameEpSeason($row['searchname']);
				if (is_array($release) && $release['name'] != '') {

					// Force local lookup only
					if ($local == true) {
						$lookupSetting = false;
					} else {
						$lookupSetting = true;
					}

					// If lookups are allowed lets try to get it.
					if ($lookupSetting) {
						if ($this->echooutput) {
							echo $this->pdo->log->primaryOver("Checking TVMaze for previously failed title: ") .
									$this->pdo->log->headerOver($release['cleanname']) .
									$this->pdo->log->primary(".");
						}

						// Get the show from TVMaze
						$tvmazeShow = $this->getShowInfo((string)$release['cleanname']);

						if (is_array($tvmazeShow)) {
							// Check if we have the TVDB ID already, if we do use that Video ID
							$dupeCheck = $this->getVideoIDFromSiteID('tvdb', $tvmazeShow['tvdb']);
							if ($dupeCheck === false) {
								$videoId = $this->add($tvmazeShow);
							} else {
								$videoId = $dupeCheck;
							}

							$tvmazeid = (int)$tvmazeShow['tvmaze'];

							if (is_numeric($videoId) && $videoId > 0 && is_numeric($tvmazeid) && $tvmazeid > 0) {
								// Now that we have valid video and tvmaze ids, try to get the poster
								$this->getPoster($videoId, $tvmazeid);

								$seasonNo = preg_replace('/^S0*/i', '', $release['season']);
								$episodeNo = preg_replace('/^E0*/i', '', $release['episode']);

								if ($episodeNo === 'all') {
									// Set the video ID and leave episode 0
									$this->setVideoIdFound($videoId, $row['id'], 0);
									echo $this->pdo->log->primary("Found TVDB Match for Full Season!");
									continue;
								}

								// Download all episodes if new show to reduce API usage
								if ($this->countEpsByVideoID($videoId) === false) {
									$this->getEpisodeInfo($tvmazeid, -1, -1, '', $videoId);
								}

								// Check if we have the episode for this video ID
								$episode = $this->getBySeasonEp($videoId, $seasonNo, $episodeNo, $release['airdate']);

								if ($episode === false) {
									// Send the request for the episode to TVMaze
									$tvmazeEpisode = $this->getEpisodeInfo(
											$tvmazeid,
											$seasonNo,
											$episodeNo,
											$release['airdate']
									);

									if ($tvmazeEpisode) {
										$episode = $this->addEpisode($videoId, $tvmazeEpisode);
									}
								}

								if ($episode !== false && is_numeric($episode) && $episode > 0) {
									// Mark the releases video and episode IDs
									$this->setVideoIdFound($videoId, $row['id'], $episode);
									if ($this->echooutput) {
										echo $this->pdo->log->primary("Found TVMaze Match!");
									}
									continue;
								}
							}
						}
					}
				} //Processing failed, set the episode ID to the next processing group
				$this->setVideoNotFound(parent::PROCESS_TMDB, $row['id']);
			}
		}
	}

	/**
	 * Calls the API to lookup the TvMaze info for a given TVDB or TVRage ID
	 * Returns a formatted array of show data or false if no match

	 * @param $site
	 * @param $siteId
	 *
	 * @return array|bool
	 */
	protected function getShowInfoBySiteID($site, $siteId)
	{
		$return = $response = false;

		//Try for the best match with AKAs embedded
		$response = $this->client->getShowBySiteID($site, $siteId);

		sleep(1);

		if (is_array($response)) {
			$return = $this->formatShowArr($response);
		}
		return $return;
	}

	/**
	 * Calls the API to perform initial show name match to TVDB title
	 * Returns a formatted array of show data or false if no match
	 *
	 * @param $cleanName
	 *
	 * @return array|bool
	 */
	protected function getShowInfo($cleanName)
	{
		$return = $response = false;

		//Try for the best match with AKAs embedded
		$response = $this->client->singleSearch($cleanName);

		sleep(1);

		if (is_array($response)) {
			$return = $this->matchShowInfo($response, $cleanName);
		}
		if ($return === false) {
			//Try for the best match via full search (no AKAs can be returned but the search is better)
			$response = $this->client->search($cleanName);
			if (is_array($response)) {
				$return = $this->matchShowInfo($response, $cleanName);
			}
		}
		//If we didn't get any aliases do a direct alias lookup
		if (is_array($return) && empty($return['aliases']) && is_numeric($return['tvmaze'])) {
			$return['aliases'] = $this->client->getShowAKAs($return['tvmaze']);
		}
		return $return;
	}

	/**
	 * @param $showArr
	 * @param $cleanName
	 *
	 * @return array|bool
	 */
	private function matchShowInfo($showArr, $cleanName)
	{
		$return = false;
		$highestMatch = 0;

		foreach ($showArr AS $show) {
			if ($this->checkRequired($show, 'tvmazeS')) {
				// Check for exact title match first and then terminate if found
				if (strtolower($show->name) === strtolower($cleanName)) {
					$highest = $show;
					break;
				} else {
					// Check each show title for similarity and then find the highest similar value
					$matchPercent = $this->checkMatch(strtolower($show->name), strtolower($cleanName), self::MATCH_PROBABILITY);

					// If new match has a higher percentage, set as new matched title
					if ($matchPercent > $highestMatch) {
						$highestMatch = $matchPercent;
						$highest = $show;
					}

					// Check for show aliases and try match those too
					if (is_array($show->akas) && !empty($show->akas)) {
						foreach ($show->akas as $key => $name) {
							$matchPercent = $this->checkMatch(strtolower($name), strtolower($cleanName), $matchPercent);
							if ($matchPercent > $highestMatch) {
								$highestMatch = $matchPercent;
								$highest = $show;
							}
						}
					}
				}
			}
		}
		if (isset($highest)) {
			$return = $this->formatShowArr($highest);
		}
		return $return;
	}

	/**
	 * Retrieves the poster art for the processed show
	 *
	 * @param int $videoId -- the local Video ID
	 * @param int $showId  -- the TVDB ID
	 *
	 * @return null
	 */
	protected function getPoster($videoId, $showId = 0)
	{
		$ri = new ReleaseImage($this->pdo);

		// Try to get the Poster
		$hascover = $ri->saveImage($videoId, sprintf($this->posterUrl), $this->imgSavePath, '', '');

		// Mark it retrieved if we saved an image
		if ($hascover == 1) {
			$this->setCoverFound($videoId);
		}
	}

	/**
	 * Gets the specific episode info for the parsed release after match
	 * Returns a formatted array of episode data or false if no match
	 *
	 * @param integer $tvmazeid
	 * @param integer $season
	 * @param integer $episode
	 * @param string  $airdate
	 * @param integer $videoId
	 *
	 * @return array|bool
	 */
	protected function getEpisodeInfo($tvmazeid, $season, $episode, $airdate = '', $videoId = 0)
	{
		$return = $response = false;

		if ($airdate !== '') {
				$response = $this->client->getEpisodesByAirdate($tvmazeid, $airdate);
		} else if ($videoId > 0) {
				$response = $this->client->getEpisodesByShowID($tvmazeid);
		} else {
				$response = $this->client->getEpisodeByNumber($tvmazeid, $season, $episode);
		}

		sleep(1);

		//Handle Single Episode Lookups
		if (is_object($response)) {
			if ($this->checkRequired($response, 'tvmazeE')) {
				$return = $this->formatEpisodeArr($response);
			}
		} else if (is_array($response)) {
			//Handle new show/all episodes and airdate lookups
			foreach ($response as $singleEpisode) {
				if ($this->checkRequired($singleEpisode, 'tvmazeE')) {
					// If this is an airdate lookup and it matches the airdate, set a return
					if ($airdate !== '' && $airdate == $singleEpisode->airdate) {
						$return = $this->formatEpisodeArr($singleEpisode);
					} else {
						// Insert the episode
						$this->addEpisode($videoId, $this->formatEpisodeArr($singleEpisode));
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Assigns API show response values to a formatted array for insertion
	 * Returns the formatted array
	 *
	 * @param $show
	 *
	 * @return array
	 */
	private function formatShowArr($show)
	{
		$this->posterUrl = (string)(isset($show->mediumImage) ? $show->mediumImage : '');

		return [
			'type'      => (int)parent::TYPE_TV,
			'title'     => (string)$show->name,
			'summary'   => (string)$show->summary,
			'started'   => (string)$show->premiered,
			'publisher' => (string)$show->network,
			'country'   => (string)$show->country,
			'source'    => (int)parent::SOURCE_TVMAZE,
			'imdb'      => 0,
			'tvdb'      => (int)(isset($show->externalIDs['thetvdb']) ? $show->externalIDs['thetvdb'] : 0),
			'tvmaze'    => (int)$show->id,
			'trakt'     => 0,
			'tvrage'    => (int)(isset($show->externalIDs['tvrage']) ? $show->externalIDs['tvrage'] : 0),
			'tmdb'      => 0,
			'aliases'   => (!empty($show->akas) ? (array)$show->akas : '')
		];
	}

	/**
	 * Assigns API episode response values to a formatted array for insertion
	 * Returns the formatted array
	 *
	 * @param $episode
	 *
	 * @return array
	 */
	private function formatEpisodeArr($episode)
	{
		return [
			'title'       => (string)$episode->name,
			'series'      => (int)$episode->season,
			'episode'     => (int)$episode->number,
			'se_complete' => (string)'S' . sprintf('%02d', $episode->season) . 'E' . sprintf('%02d', $episode->number),
			'firstaired'  => (string)$episode->airdate,
			'summary'     => (string)$episode->summary
		];
	}
}
