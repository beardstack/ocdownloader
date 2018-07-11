<?php

namespace OCA\ocDownloader\Controller\Lib;

class db_queue
{
        /*
        "ALL" = 5
        "COMPLETES" = 0
        "ACTIVE" = 1
        "WAITINGS" = 2
        "STOPPED" = 3
        "REMOVED" = 4
         */      
        public function add($a) {
                $SQL = 'INSERT INTO `*PREFIX*ocdownloader_queue`
                (`UID`, `GID`, `FILENAME`, `PROTOCOL`, `STATUS`, `TIMESTAMP`) VALUES(?, ?, ?, ?, ?, ?)';
                
                if ($this->DbType == 1) {
                    $SQL = 'INSERT INTO *PREFIX*ocdownloader_queue
                        ("UID", "GID", "FILENAME", "PROTOCOL", "STATUS", "TIMESTAMP") VALUES(?, ?, ?, ?, ?, ?)';
                }
                $Query = \OCP\DB::prepare($SQL);
                $Result = $Query->execute(array(
                    $a['UID'],
                    $a['GID'],
                    $a['FILENAME'],
                    $a['PROTOCOL'],
                    1, # ACTIVE
                    time()
                ));
        }
        public function update($a)
            {
                $SQL = 'UPDATE `*PREFIX*ocdownloader_queue`
                            SET `STATUS` = ? WHERE `UID` = ? AND `GID` = ?';
                        if ($this->DbType == 1) {
                            $SQL = 'UPDATE *PREFIX*ocdownloader_queue
                                SET "STATUS" = ? WHERE "UID" = ? AND "GID" = ?';
                        }
                        $Query = \OCP\DB::prepare($SQL);
                        $Result = $Query->execute(array(
                                $a['STATUS'], 
                                $a['UID'],
                                $a['GID']
                        ));
            }
}
class DownloadDetails {
	
	private $GID = NULL;
	private $playlist_name = '';
	private $filename;
        private $statusArray = array(
                'status' => 'waiting',
                'completed' => '',
                'size' => '',
                'downloadspeed' => ''
            );
	private $pipe;
	private $pipehandle;
	private $UID;
	private $PROTOCOL;
	
	function __construct($UID,$PROTOCOL )	{
		$this->GID = uniqid();
		$this->createpipe();
		$this->UID = $UID;
		$this->PROTOCOL = $PROTOCOL;
	}
	
	function __destruct() {
		$this->destroypipe();
	}
	
	private function createpipe(){
		#create an output pipe in /tmp/$GID
		#$this->pipe = "/tmp/" . $this->GID;
		$this->pipe = "/tmp/pipe" ;
		
		if(!file_exists($this->pipe)) {
			umask(0);
      			posix_mkfifo($this->pipe,0600);
		}
		$this->pipehandle = fopen($this->pipe,"r+"); 
	}
	public function destroypipe(){
		unlink($this->pipe); //delete pipe
	}
	
	public function writetopipe($a){
		//fwrite($this->pipehandle,$a);  //block until there is a reader
		// Write the contents to the file,
		// using the FILE_APPEND flag to append the content to the end of the file
		// and the LOCK_EX flag to prevent anyone else writing to the file at the same time
		file_put_contents($this->pipe, $a. "\n", LOCK_EX);
	}
	
	public function setplaylist_name($a){
		$this->playlist_name = $a;
	}
	public function setfilename($a){
		$this->filename = $a;
	}
	
	public function getGID(){
		return $this->GID;
	}
	public function getplaylist_name() {
		return $this->playlist_name;
	}
	public function getfilename() {
		return $this->filename;
	}
	
	public function updatestatus($a) {
		$this->statusArray['status'] = $a;
	}
	public function updateprogress($a, $b, $c ) {
		$this->statusArray['completed'] = $a;
		$this->statusArray['downloadspeed'] = $b;
		$this->statusArray['size'] = $c;
	}
	public function getstatusArray(){
		return $this->statusArray;
	}
	public function getJSONstatus(){
		
		$stat->GID = $this->getGID();
		$stat->filename = $this->getfilename();
		$stat->playlist = $this->getplaylist_name();
		$stat->statusArray = $this->getstatusArray();
		
		return json_encode($stat);
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
    private $downloader;
	
    private $db;
    private $values;
	
    function __construct($cmd = '', $UID, $PROTO)
    {
        $this->cmd = $cmd;
        $this->resource = proc_open($this->cmd, $this->descriptors, $this->pipes);
	$this->values['UID'] = $UID;
	$this->values['PROTOCOL'] = $PROTO;
	    
    }
   
    public function queue()
    {
       # return stream_get_contents($this->pipes[1]);
	while (!feof($this->pipes[1]))
	{
		$line = fgets($this->pipes[1]);
		error_log("line: " . $line ,0);
		$this->parse($line);
	}
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
		$this->downloadstatusArray[$this->arrayindex] = new DownloadDetails($this->UID, $this->PROTOCOL);
		$this->downloader = $this->downloadstatusArray[$this->arrayindex];
		
		if (!is_null($this->temp)) {
			$this->downloader->setplaylist_name($this->temp);
		}
		$this->downloader->setfilename($path_parts['filename']);
		error_log($this->downloader->getJSONstatus() ,0);
		
		$this->values['FILENAME'] = $path_parts['filename'];
		$this->values['GID'] = $this->downloader->getGID();
		#$this->db = new db_queue();
		#$this->db->add($this->values);
	
		#$this->downloader->writetopipe($this->downloader->getJSONstatus());
		
		
		
        }
	#extract completion updates
	elseif (preg_match('/\[download\]\s*(.+)\% of (.+) at (.+) ETA /i', $in, $out)) { 
		#$out[1] = %complete, $out[2] = size, #$out[3] = speed
		#error_log("%complete: ". $out[1] . " size: ". $out[2] . " speed: ". $out[3]   ,0);
		
		$this->downloader->updateprogress($out[1], $out[3], $out[2] );
		$this->downloader->updatestatus('Downloading');
		error_log($this->downloader->getJSONstatus() ,0);
		$this->downloader->writetopipe($this->downloader->getJSONstatus());
        }
	#Status = post-processing
	elseif (preg_match('/\[ffmpeg\] Destination:/i', $in)) { 
		$this->downloader->updatestatus('Post-Processing');
		error_log($this->downloader->getJSONstatus() ,0);
		$this->downloader->writetopipe($this->downloader->getJSONstatus());
        }
	#Status = DONE increment arrayindex
	elseif (preg_match('/\[ffmpeg\] Adding thumbnail/i', $in)) { 
		$this->downloader->updatestatus('Completed');
		$this->downloader->writetopipe($this->downloader->getJSONstatus());
		
		#update database
		$this->values['STATUS'] = 0; //COMPLETE
		#$this->db->update($this->values);
		
		error_log($this->downloader->getJSONstatus() ,0);
		#$this->downloader->destroypipe();
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
    private $DID;
/*
	public function __construct($YTDLBinary, $URL)
    {
        $this->YTDLBinary = $YTDLBinary;
        $this->URL = $URL;
    }
    */
	
    public function __construct($YTDLBinary, $UID, $DID)
    {
        $this->YTDLBinary = $YTDLBinary;
        $this->CurrentUID = $UID;
	$this->DID = $DID;
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

	
	public function dl($ExtractAudio = false){
		error_log("HERE --> EXECUTING!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!", 0);
		$cmd = $this->YTDLBinary . " --batch-file " . $this->DID ."/url " . "--config-location " . $this->DID ."/yt-dl.conf";
		$this->process = new RunYTDL($cmd, $this->CurrentUID, "YT_Audio" );
	 	$this->YTDLOutput = $this->process->queue();
		$this->syncDownloadsFolder();
		error_log("HERE --> EXECUTING!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!", 0);
	}
	
}

/*


$argv[0] = youtube-dl path
$argv[1] = UID
$argv[2] = DID

Need to gather youtube-dl path
Need to gather UID (User ID)
Need to gather DID Param (UniqueID for the directory in which configs are stored)

Downloading specs are in the /tmp/ytdownloader/$ID directory


*/

error_log("ARGUMENTS!!!! ----> " . $argv[1]. " ". $argv[2] . " " . $argv[3]  , 0);
$YouTube = new YouTube($argv[1], $argv[2], $argv[3] );
error_log("2" , 0);

$YouTube->dl();
error_log("OOM" , 0);

?>
