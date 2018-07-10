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
