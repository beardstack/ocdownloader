<?php
/**
 * Nextcloud - ncDownloader
 *
*/

namespace OCA\ocDownloader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;

use OCP\IL10N;
use OCP\IRequest;

use OCA\ocDownloader\Controller\Lib\YouTube;
use OCA\ocDownloader\Controller\Lib\Tools;
use OCA\ocDownloader\Controller\Lib\Aria2;
use OCA\ocDownloader\Controller\Lib\CURL;
use OCA\ocDownloader\Controller\Lib\Settings;

class YTDownloader extends Controller
{
    private $AbsoluteDownloadsFolder = null;
    private $DownloadsFolder = null;
    private $DbType = 0;
    private $YTDLBinary = null;
    private $ProxyAddress = null;
    private $ProxyPort = 0;
    private $ProxyUser = null;
    private $ProxyPasswd = null;
    private $ProxyOnlyWithYTDL = null;
    private $WhichDownloader = 0;
    private $CurrentUID = null;
    private $L10N = null;
    private $AllowProtocolYT = null;
    private $MaxDownloadSpeed = null;
    private $VideoData = null;

    public function __construct($AppName, IRequest $Request, $CurrentUID, IL10N $L10N)
    {
        parent::__construct($AppName, $Request);

        if (strcmp(\OC::$server->getConfig()->getSystemValue('dbtype'), 'pgsql') == 0) {
            $this->DbType = 1;
        }

        $this->CurrentUID = $CurrentUID;

        $Settings = new Settings();
        $Settings->setKey('YTDLBinary');
        $YTDLBinary = $Settings->getValue();

        $this->YTDLBinary = '/usr/local/bin/youtube-dl'; // default path
        if (!is_null($YTDLBinary)) {
            $this->YTDLBinary = $YTDLBinary;
        }

        $Settings->setKey('ProxyAddress');
        $this->ProxyAddress = $Settings->getValue();
        $Settings->setKey('ProxyPort');
        $this->ProxyPort = intval($Settings->getValue());
        $Settings->setKey('ProxyUser');
        $this->ProxyUser = $Settings->getValue();
        $Settings->setKey('ProxyPasswd');
        $this->ProxyPasswd = $Settings->getValue();
        $Settings->setKey('WhichDownloader');
        $this->WhichDownloader = $Settings->getValue();
        $this->WhichDownloader = is_null($this->WhichDownloader) ? 0 :(strcmp($this->WhichDownloader, 'ARIA2') == 0 ? 0 : 1); // 0 means ARIA2, 1 means CURL
        $Settings->setKey('MaxDownloadSpeed');
        $this->MaxDownloadSpeed = $Settings->getValue();
        $Settings->setKey('AllowProtocolYT');
        $this->AllowProtocolYT = $Settings->getValue();
        $this->AllowProtocolYT = is_null($this->AllowProtocolYT) ? true : strcmp($this->AllowProtocolYT, 'Y') == 0;

        $Settings->setTable('personal');
        $Settings->setUID($this->CurrentUID);
        $Settings->setKey('DownloadsFolder');
        $this->DownloadsFolder = $Settings->getValue();

        $this->DownloadsFolder = '/' .(is_null($this->DownloadsFolder) ? 'Downloads' : $this->DownloadsFolder);
        $this->AbsoluteDownloadsFolder = \OC\Files\Filesystem::getLocalFolder($this->DownloadsFolder);

        $this->L10N = $L10N;
    }

      /**
       * @NoAdminRequired
       * @NoCSRFRequired
       */
    public function add()
    {
        \OCP\JSON::setContentTypeHeader('application/json');

        if (isset($_POST['FILE']) && strlen($_POST['FILE']) > 0
              && Tools::checkURL($_POST['FILE']) && isset($_POST['OPTIONS'])) {
            try {
		    
                if (!$this->AllowProtocolYT && !\OC_User::isAdminUser($this->CurrentUID)) {
                    throw new \Exception((string)$this->L10N->t('You are not allowed to use the YouTube protocol'));
                }
		
		$path = "/tmp/ytdownloader/" . uniqid();
		    
		if (!file_exists($path)) { 
			mkdir($path, 0777, true);
		}    
		    
		/* write url to file */
		$yt_input = $path . '/url';
		$handle = fopen($yt_input, 'a') or die('Cannot open file:  '.$yt_input);
		fwrite($handle,  $_POST['FILE'] . "\n");  
		fclose($handle);
		
		/* write configuration to file */
		$yt_config = $path . '/yt-dl.conf';
		$handle = fopen($yt_config, 'a') or die('Cannot open file:  '.$yt_config);
		
		
		 

                $YouTube = new YouTube($this->YTDLBinary, $_POST['FILE']);

                if (!is_null($this->ProxyAddress) && $this->ProxyPort > 0 && $this->ProxyPort <= 65536) {
			$YouTube->SetProxy($this->ProxyAddress, $this->ProxyPort);
			fwrite($handle, "--proxy " . $this->ProxyAddress . ":" . $this->ProxyPort ."\n");
                }

                if (isset($_POST['OPTIONS']['YTForceIPv4']) && strcmp($_POST['OPTIONS']['YTForceIPv4'], 'false') == 0) {
			$YouTube->SetForceIPv4(false);
                } else { fwrite($handle, "--force-ipv4 " . "\n"); }

		if (!is_null($this->AbsoluteDownloadsFolder)) {
			$YouTube->SetDirectory($this->AbsoluteDownloadsFolder);
		} else { error_log("AbsoluteDownloadsFolder is null", 0); }

		if (!is_null($this->DownloadsFolder)) {
			$YouTube->setDownloadsFolder($this->DownloadsFolder);
		} else { error_log("DownloadsFolder is null", 0); }

		$YouTube->setCurrentUID($this->CurrentUID);

                // Extract Audio YES
                if (isset($_POST['OPTIONS']['YTExtractAudio'])
                && strcmp($_POST['OPTIONS']['YTExtractAudio'], 'true') == 0) {
			
			fwrite($handle, "--newline" . "\n");  
                        fwrite($handle, "--ignore-errors" . "\n");                                                                                                                                                       
		        fwrite($handle, "--extract-audio" . "\n");                                                                                                                                                                          
			fwrite($handle, "--embed-thumbnail" . "\n");
			fwrite($handle, "--add-metadata" . "\n");
			fwrite($handle, "--no-mtime " . "\n");
			fwrite($handle, "--output '%(playlist)s/%(title)s.%(ext)s' " . "\n");
			fwrite($handle, "--audio-format mp3" . "\n");
			fwrite($handle, "--yes-playlist" . "\n");
			fwrite($handle, "--restrict-filenames" . "\n");
			
			fclose($handle);
			
			
/*
			$pid = pcntl_fork();
			if ($pid == -1) {
			     die('could not fork');
			} else if ($pid) {
			     // we are the parent
			     pcntl_wait($status); //Protect against Zombie children
			} else {
			     $YouTube->download(true);
			}
                        
*/
			$VideoData = $YouTube->download(true);

			
			return new JSONResponse(array(
                              'ERROR' => true,
                              'MESSAGE' =>(string)$this->L10N->t('OK')
                        ));
			
                   # $VideoData = $YouTube->download(true);

                } else // No audio extract
                {
                    $VideoData = $YouTube->download();

                }


            } catch (Exception $E) {
                return new JSONResponse(array('ERROR' => true, 'MESSAGE' => $E->getMessage()));
            }
        } else {
            return new JSONResponse(
                array(
                    'ERROR' => true,
                    'MESSAGE' =>(string)$this->L10N->t('Please check the URL you\'ve just provided')
                )
            );
        }
    }
}
