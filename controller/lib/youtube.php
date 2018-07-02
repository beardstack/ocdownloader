<?php
/**
 * ownCloud - ocDownloader
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE file.
 *
 * @author Xavier Beurois <www.sgc-univ.net>
 * @copyright Xavier Beurois 2015
 */

namespace OCA\ocDownloader\Controller\Lib;

class RunYTDL {

    public $cmd = '';
    private $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        );
    public $pipes = NULL;
    public $resource = NULL;
    private $exitcode = NULL;

    function __construct($cmd = '')
    {
        $this->cmd = $cmd;
        $this->resource = proc_open($this->cmd, $this->descriptors, $this->pipes);
    }
   
    public function getContents()
    {
        return stream_get_contents($this->pipes[1]);
    }

    public function isRunning()
    {
        $status = proc_get_status($this->resource);
        if ($status['running'] === FALSE && $this->exitcode === NULL)
            $this->exitcode = $status['exitcode'];

        return $status['running'];
    }

    public function getExitcode()
    {
        return $this->exitcode;
    }
}

class YouTube
{
    private $YTDLBinary = null;
    private $URL = null;
    private $ForceIPv4 = true;
    private $ProxyAddress = null;
    private $ProxyPort = 0;
    private $Directory = null;
    private $CurrentUID;
    private $DownloadsFolder;

    public function __construct($YTDLBinary, $URL)
    {
        $this->YTDLBinary = $YTDLBinary;
        $this->URL = $URL;
    }

    public function setForceIPv4($ForceIPv4)
    {
        $this->ForceIPv4 = $ForceIPv4;
    }

    public function setProxy($ProxyAddress, $ProxyPort)
    {
        $this->ProxyAddress = $ProxyAddress;
        $this->ProxyPort = $ProxyPort;
    }
   
    public function setDirectory($dir)
    {
       $this->Directory = $dir;
    }

    public function setDownloadsFolder($dir)
    {
       $this->DownloadsFolder = $dir; 
    }

    public function setCurrentUID($UID)
    {
       $this->CurrentUID = $UID;
    }

    protected function syncDownloadsFolder()
    {
        $user = $this->CurrentUID;
        $scanner = new \OC\Files\Utils\Scanner($user, \OC::$server->getDatabaseConnection(), \OC::$server->getLogger());
        $path = '/'.$user.'/files/'.ltrim($this->DownloadsFolder, '/\\');

        try {
             $scanner->scan($path);
        } 
        catch (ForbiddenException $e) {
            #forbidden error
	    error_log("HERE --> Forbidden error",0);
        } 
        catch (\Exception $e) {
            #other error
	    error_log("HERE --> Other Error". $e ,0);
        }

    }


    public function getVideoData($ExtractAudio = false)
    {
        $Proxy = null;
        if (!is_null($this->ProxyAddress) && $this->ProxyPort > 0 && $this->ProxyPort <= 65536) {
            $Proxy = ' --proxy ' . rtrim($this->ProxyAddress, '/') . ':' . $this->ProxyPort;
        }


        //youtube multibyte support
        putenv('LANG=en_US.UTF-8');

#        $Output = shell_exec(
#             $this->YTDLBinary.' -i \''.$this->URL.'\' '
	      #.'-o ' . $this->Directory .'/\'%(title)s.%(ext)s\''
	    #);

#	error_log("YTDL Output:". $Output, 0);
	$ytdl=explode("\n", $Output);
	foreach ($ytdl as $y) { error_log ("YTDL Output:". $y."\n"); }
	
	$this->syncDownloadsFolder();
	error_log("HERE --> Scan done", 0);

        $index=(preg_match('/&index=(\d+)/', $this->URL, $current))?$current[1]:1;

        if (!is_null($Output)) {
            $Output = explode("\n", $Output);

            if (count($Output) >= 2) {
                $OutProcessed = array();
                $current_index=1;
                for ($I = 0; $I < count($Output); $I++) {
                    if (mb_strlen(trim($Output[$I])) > 0) {
                        if (mb_strpos(urldecode($Output[$I]), 'https://') === 0
                                && mb_strpos(urldecode($Output[$I]), '&mime=video/') !== false) {
                            $OutProcessed['VIDEO'] = $Output[$I];
                        } elseif (mb_strpos(urldecode($Output[$I]), 'https://') === 0
                                && mb_strpos(urldecode($Output[$I]), '&mime=audio/') !== false) {
                            $OutProcessed['AUDIO'] = $Output[$I];
                        } else {
                            $OutProcessed['FULLNAME'] = $Output[$I];
                        }
                    }
                 if ((!empty($OutProcessed['VIDEO']) || !empty($OutProcessed['AUDIO'])) && !empty($OutProcessed['FULLNAME']))
                    {
                        if ($index == $current_index)
                        {
                            break;
                        } else {
                            $OutProcessed = array();
                            $current_index++;
                        }
                    }
                }
                return $OutProcessed;
            }
        }
        return null;
    }

}
