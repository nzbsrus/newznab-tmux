<?php
require_once(dirname(__FILE__)."/../bin/config.php");
require_once(WWW_DIR."/lib/framework/db.php");
require_once(WWW_DIR."/lib/nntp.php");
require_once(WWW_DIR . "/lib/nzb.php");
require_once(WWW_DIR."/lib/Tmux.php");
require_once(WWW_DIR . "/lib/util.php");
require_once("ColorCLI.php");
require_once("Info.php");
require_once("Pprocess.php");
require_once("Enzebe.php");

/**
 * Gets information contained within the NZB.
 *
 * Class NZBContents
 */
Class NZBContents
{
	/**
	 * @var DB
	 * @access protected
	 */
	public $db;

	/**
	 * @var NNTP
	 * @access protected
	 */
	protected $nntp;

	/**
	 * @var Info
	 * @access protected
	 */
	protected $nfo;

	/**
	 * @var PProcess
	 * @access protected
	 */
	protected $pp;

	/**
	 * @var Enzebe
	 * @access protected
	 */
	protected $nzb;

	/**
	 * @var bool|stdClass
	 * @access protected
	 */
	protected $site;

	/**
	 * @var bool
	 * @access protected
	 */
	protected $lookuppar2;

	/**
	 * @var bool
	 * @access protected
	 */
	protected $echooutput;

	/**
	 * Construct.
	 *
	 * @param array $options
	 *     array(
	 *         'Echo'        => bool        ; To echo to CLI or not.
	 *         'NNTP'        => NNTP        ; Class NNTP.
	 *         'Nfo'         => Nfo         ; Class Info.
	 *         'NZB'         => NZB         ; Class NZB.
	 *         'DB'          => DB          ; Class DB.
	 *         'PostProcess' => PProcess ; Class PProcess.
	 *     )
	 *
	 * @access public
	 */
	public function __construct(array $options = array())
	{
		$defaults = [
			'Echo'        => false,
			'NNTP'        => null,
			'Nfo'         => null,
			'NZB'         => null,
			'DB'          => null,
			'PostProcess' => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo']);
		$this->db = ($options['DB'] instanceof DB ? $options['DB'] : new DB());
		$this->nntp = ($options['NNTP'] instanceof NNTP ? $options['NNTP'] : new NNTP());
		$this->nfo = ($options['Nfo'] instanceof Info ? $options['Nfo'] : new Info());
		$this->pp = (
		$options['PostProcess'] instanceof PProcess ? $options['PostProcess'] : new PProcess());
		$this->nzb = ($options['NZB'] instanceof Enzebe ? $options['NZB'] : new Enzebe());
		$t = new Tmux();
		$this->tmux = $t->get();
		$this->lookuppar2 = ($this->tmux->lookuppar2 == 1 ? true : false);
	}

	/**
	 * Look for an .nfo file in the NZB, return the NFO message id.
	 * Gets the NZB completion.
	 * Looks for PAR2 files in the NZB.
	 *
	 * @param string $guid
	 * @param string $relID
	 * @param int    $groupID
	 * @param string $groupName
	 *
	 * @return bool
	 *
	 * @access public
	 */
	public function getNfoFromNZB($guid, $relID, $groupID, $groupName)
	{
		$fetchedBinary = false;

		$messageID = $this->parseNZB($guid, $relID, $groupID, true);
		if ($messageID !== false) {
			$fetchedBinary = $this->nntp->getMessages($groupName, $messageID['ID']);
			if ($this->nntp->isError($fetchedBinary)) {
				// NFO download failed, increment attempts.
				$this->db->queryExec(sprintf('UPDATE releases SET nfostatus = nfostatus - 1 WHERE ID = %d', $relID));
				if ($this->echooutput) {
					echo 'f';
				}
				return false;
			}
			if ($this->nfo->isNFO($fetchedBinary, $guid) === true) {
				if ($this->echooutput) {
					echo ($messageID['hidden'] === false ? '+' : '*');
				}
			} else {
				if ($this->echooutput) {
					echo '-';
				}
				$this->db->queryExec(sprintf('UPDATE releases SET nfostatus = %d WHERE ID = %d', Info::NFO_NONFO, $relID));
				$fetchedBinary = false;
			}
		} else {
			if ($this->echooutput) {
				echo '-';
			}
			$this->db->queryExec(sprintf('UPDATE releases SET nfostatus = %d WHERE ID = %d', Info::NFO_NONFO, $relID));
		}

		return $fetchedBinary;
	}

	/**
	 * Attempts to get the releasename from a par2 file
	 *
	 * @param string $guid
	 * @param int    $relID
	 * @param int    $groupID
	 * @param int    $nameStatus
	 * @param int    $show
	 *
	 * @return bool
	 *
	 * @access public
	 */
	public function checkPAR2($guid, $relID, $groupID, $nameStatus, $show)
	{
		$nzbFile = $this->LoadNZB($guid);
		if ($nzbFile !== false) {
			foreach ($nzbFile->file as $nzbContents) {
				if (preg_match('/\.(par[2" ]|\d{2,3}").+\(1\/1\)$/i', (string)$nzbContents->attributes()->subject)) {
					if ($this->pp->parsePAR2((string)$nzbContents->segments->segment, $relID, $groupID, $this->nntp, $show) === true && $nameStatus === 1) {
						$this->db->queryExec(sprintf('UPDATE releases SET proc_par2 = 1 WHERE ID = %d', $relID));

						return true;
					}
				}
			}
		}
		if ($nameStatus === 1) {
			$this->db->queryExec(sprintf('UPDATE releases SET proc_par2 = 1 WHERE ID = %d', $relID));
		}
		return false;
	}

	/**
	 * Gets the completion from the NZB, optionally looks if there is an NFO/PAR2 file.
	 *
	 * @param string $guid
	 * @param int    $relID
	 * @param int    $groupID
	 * @param bool   $nfoCheck
	 *
	 * @return array|bool
	 *
	 * @access public
	 */
	public function parseNZB($guid, $relID, $groupID, $nfoCheck = false)
	{
		$nzbFile = $this->LoadNZB($guid);
		if ($nzbFile !== false) {
			$messageID = $hiddenID = '';
			$actualParts = $artificialParts = 0;
			$foundPAR2 = ($this->lookuppar2 === false ? true : false);
			$foundNFO = $hiddenNFO = ($nfoCheck === false ? true : false);

			foreach ($nzbFile->file as $nzbcontents) {
				foreach ($nzbcontents->segments->segment as $segment) {
					$actualParts++;
				}

				$subject = (string)$nzbcontents->attributes()->subject;
				if (preg_match('/(\d+)\)$/', $subject, $parts)) {
					$artificialParts += $parts[1];
				}

				if ($foundNFO === false) {
					if (preg_match('/\.\b(nfo|inf|ofn)\b(?![ .-])/i', $subject)) {
						$messageID = (string)$nzbcontents->segments->segment;
						$foundNFO = true;
					}
				}

				if ($foundNFO === false && $hiddenNFO === false) {
					if (preg_match('/\(1\/1\)$/i', $subject) &&
						!preg_match('/\.(apk|bat|bmp|cbr|cbz|cfg|css|csv|cue|db|dll|doc|epub|exe|gif|htm|ico|idx|ini' .
							'|jpg|lit|log|m3u|mid|mobi|mp3|nib|nzb|odt|opf|otf|par|par2|pdf|psd|pps|png|ppt|r\d{2,4}' .
							'|rar|sfv|srr|sub|srt|sql|rom|rtf|tif|torrent|ttf|txt|vb|vol\d+\+\d+|wps|xml|zip)/i',
							$subject))
					{
						$hiddenID = (string)$nzbcontents->segments->segment;
						$hiddenNFO = true;
					}
				}

				if ($foundPAR2 === false) {
					if (preg_match('/\.(par[2" ]|\d{2,3}").+\(1\/1\)$/i', $subject)) {
						if ($this->pp->parsePAR2((string)$nzbcontents->segments->segment, $relID, $groupID, $this->nntp, 1) === true) {
							$this->db->queryExec(sprintf('UPDATE releases SET proc_par2 = 1 WHERE ID = %d', $relID));
							$foundPAR2 = true;
						}
					}
				}
			}

			if ($artificialParts <= 0 || $actualParts <= 0) {
				$completion = 0;
			} else {
				$completion = ($actualParts / $artificialParts) * 100;
			}
			if ($completion > 100) {
				$completion = 100;
			}

			$this->db->queryExec(sprintf('UPDATE releases SET completion = %d WHERE ID = %d', $completion, $relID));

			if ($foundNFO === true && strlen($messageID) > 1) {
				return array('hidden' => false, 'ID' => $messageID);
			} elseif ($hiddenNFO === true && strlen($hiddenID) > 1) {
				return array('hidden' => true, 'ID' => $hiddenID);
			}
		}
		return false;
	}

	/**
	 * Decompress a NZB, load it into simplexml and return.
	 *
	 * @param string $guid Release guid.
	 *
	 * @return bool|SimpleXMLElement
	 *
	 * @access public
	 */
	public function LoadNZB(&$guid)
	{
		// Fetch the NZB location using the GUID.
		$nzbPath = $this->nzb->NZBPath($guid);
		if ($nzbPath === false) {
			if ($this->echooutput) {
				echo PHP_EOL . $guid . ' appears to be missing the nzb file, skipping.' . PHP_EOL;
			}
			return false;
		}

		$nzbContents = Utility::unzipGzipFile($nzbPath);
		if (!$nzbContents) {
			if ($this->echooutput) {
				echo
					PHP_EOL .
					'Unable to decompress: ' .
					$nzbPath .
					' - ' .
					fileperms($nzbPath) .
					' - may have bad file permissions, skipping.' .
					PHP_EOL;
			}
			return false;
		}

		$nzbFile = @simplexml_load_string($nzbContents);
		if (!$nzbFile) {
			if ($this->echooutput) {
				echo PHP_EOL . "Unable to load NZB: $guid appears to be an invalid NZB, skipping." . PHP_EOL;
			}
			return false;
		}

		return $nzbFile;
	}
}