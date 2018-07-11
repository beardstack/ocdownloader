<?php

namespace OCA\ocDownloader\Controller;

use OCA\ocDownloader\Controller\Lib\YouTube;

$YouTube = new YouTube($this->YTDLBinary, $_POST['FILE']);

