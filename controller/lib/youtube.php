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


class DownloadDetails {
	
	private $GID = NULL;
	private $playlist_name;
	private $filename;
        private $statusArray = array(
                'status' => 'waiting',
                'completed' => '',
                'size' => '',
                'downloadspeed' => ''
            );

	
	function __construct()	{
		$this->GID = uniqid();
	}
	
	public function setplaylist_name($a){
		$this->playlist_name = $a;
	}
	public function setfilename($a){
		$this->filename = $a;
	}
	
	public function getplaylist_name() {
		return $this->playlist_name;
	}
	public function getfilename() {
		return $this->filename;
	}
	
	public function updatestatus($a) {
		$this->$statusArray['status'] = $a;
	}
	public function updateprogress($a, $b, $c ) {
		$this->$statusArray['completed'] = $a;
		$this->$statusArray['downloadspeed'] = $b;
		$this->$statusArray['size'] = $c;
	}
	
}

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
    private $downloadstatusArray = array();
    private $arrayindex = 0; 
    private $temp = ''; #holds playlist data

    function __construct($cmd = '')
    {
        $this->cmd = $cmd;
        $this->resource = proc_open($this->cmd, $this->descriptors, $this->pipes);
	    
    }
   
    public function queue()
    {
       # return stream_get_contents($this->pipes[1]);
	while (!feof($this->pipes[1]))
	{
		$line = fgets($this->pipes[1]);
		$this->parse($line);
	}
	return '';	
    }

    private function parse($in)
    {
	#extract playlist info
	if (preg_match('/\[download\] Downloading playlist: (.*)/i', $in, $out)) { 
		error_log("Playlist Name: ". $out[1] ,0);
		$this->temp = $out[1]; 
        }
	#extract file being downloaded
	elseif (preg_match('/\[youtube\] .* Writing thumbnail to: (.*)/i', $in, $out)) { 
		$path_parts = pathinfo($out[1]);
		#error_log("File: " . $path_parts['filename'] ,0);
		
		#create a new download stat object
		$this->downloadstatusArray[$this->arrayindex] = new DownloadDetails();
		
		if (!is_null($this->temp)) {
			$this->downloadstatusArray[$this->arrayindex]->setplaylist_name($this->temp);
		}
		$this->downloadstatusArray[$this->arrayindex]->setplaylist_name($this->temp);
		$this->downloadstatusArray[$this->arrayindex]->setfilename($path_parts['filename']);

        }
	#extract completion updates
	elseif (preg_match('/\[download\]\s*(.+)\% of (.+) at (.+) ETA /i', $in, $out)) { 
		#$out[1] = %complete, $out[2] = size, #$out[3] = speed
		#error_log("%complete: ". $out[1] . " size: ". $out[2] . " speed: ". $out[3]   ,0);
		
		$this->downloadstatusArray[$this->arrayindex]->updateprogress($out[1], $out[3], $out[2] );
		$this->downloadstatusArray[$this->arrayindex]->updatestatus('Downloading');
		

        }
	#Status = post-processing
	elseif (preg_match('/\[ffmpeg\] Destination:/i', $in)) { 
		error_log("Post-Processing" ,0);
		$this->downloadstatusArray[$this->arrayindex]->updatestatus('Post-Processing');
        }
	#Status = DONE increment arrayindex
	elseif (preg_match('/\[ffmpeg\] Adding thumbnail/i', $in)) { 
		error_log("Finished" ,0);
		$this->downloadstatusArray[$this->arrayindex]->updatestatus('Post-Processing');
		$this->arrayindex++;
        }

	#else { error_log($statusUpdate,0); } 
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
    private $process = null;
    private $YTDLOutput;
    private $GID;

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

        $cmd = $this->YTDLBinary.' --newline -i \''.$this->URL.'\' ' .'-o ' . $this->Directory .'/\'%(title)s.%(ext)s\'';

	$this->process = new RunYTDL($cmd);

	if($this->process->isRunning())
	{
		$this->YTDLOutput = $this->process->queue();
	}
	
	
	$this->syncDownloadsFolder();
	error_log("HERE --> Scan done", 0);
/*
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
       } */
        return null;
    } 

}
