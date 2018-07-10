<?php


namespace OCA\ocDownloader\Controller\Lib;

class db_queue
{

        $SQL = 'INSERT INTO `*PREFIX*ocdownloader_queue`
            (`UID`, `GID`, `FILENAME`, `PROTOCOL`, `STATUS`, `TIMESTAMP`) VALUES(?, ?, ?, ?, ?, ?)';
        #if ($this->DbType == 1) {
        #    $SQL = 'INSERT INTO *PREFIX*ocdownloader_queue
        #        ("UID", "GID", "FILENAME", "PROTOCOL", "STATUS", "TIMESTAMP") VALUES(?, ?, ?, ?, ?, ?)';
        #}
        #$Query = \OCP\DB::prepare($SQL);
        #$Result = $Query->execute(array(
        #    $this->CurrentUID,
        #    $AddURI['result'],
        #    $Target,
        #    strtoupper(substr($_POST['FILE'], 0, strpos($_POST['FILE'], ':'))),
        #    1,
        #    time()
        #));


}
