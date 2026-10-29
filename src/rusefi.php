<?php

include "mlg.format.php";

/*
 * @brief Defines the actions taken at the user level.
 * 
 * upload
 * browse
 * view
 * etc.
 */
class Rusefi
{
	private $msqur = null;
	public $msq;
	public $username;
	public $userid;
	public $isAdminUser;
	public $userTunes;
	
	public $forum_url = "https://rusefi.com/forum";
	public $forum_login_url, $forum_user_profile_url;

	private $reqLogFields = array("time", "rpm", "clt", "tps", "air_fuel_ratio", "vehicle_speed", "throttle_pedal_position");

	function __construct($msqur)
	{
		global $_COOKIE, $_POST;
		$this->msqur = $msqur;

		$this->userid = -1;
		$this->isAdminUser = false;
		
		// first, get userid from phpbb SESSION_ID
		if (isset($_COOKIE['phpbb3_1lnf6_sid'])) {
			$sid = $_COOKIE['phpbb3_1lnf6_sid'];
			$this->userid = $this->getUserIdFromSid($sid);
			// Anonymous user?
			if ($this->userid <= 1)
				$this->userid = -1;
		}
		
		// if failed, try to get userid from the stored 'token' cookie
		if ($this->userid < 0 && isset($_COOKIE['rusefi_token'])) {
			$rusefi_token = $_COOKIE['rusefi_token'];
			$this->userid = $this->getUserIdFromToken($rusefi_token);
		}

		// if failed, try to get userid from the passed POST parameter
		if ($this->userid < 0 && isset($_POST['rusefi_token'])) {
			$rusefi_token = $_POST['rusefi_token'];
			$this->userid = $this->getUserIdFromToken($rusefi_token);
		}

		$this->username = ($this->userid != -1) ? $this->getUserNameFromId($this->userid) : "";
		
		//!!!!!!!!!!!
		//$this->userid = 2;
		//$this->username = "AndreyB";
	
		$this->isAdminUser = in_array($this->userid, RUSEFI_ADMIN_USER_IDS);
		$this->forum_login_url = $this->forum_url . "/ucp.php?mode=login";
		$this->forum_user_profile_url = $this->forum_url . "/memberlist.php?mode=viewprofile&u=" . $this->userid;

		$this->userTunes = $this->getUserTunes($this->userid);
	}

	public function getUserIdFromToken($token)
	{
		if (!$this->msqur->db->connect()) return -1;
		try
		{
			$st = $this->msqur->db->db->prepare("SELECT user_id FROM phpbb_rusefi_tokens WHERE token = :rusefi_token LIMIT 1");
			DB::tryBind($st, ":rusefi_token", $token);
			$st->execute();
			if ($st->rowCount() > 0)
			{
				$result = $st->fetch(PDO::FETCH_ASSOC);
				$user_id = $result['user_id'];
				$st->closeCursor();
				return $user_id;
			}
			else
			{
				if (DEBUG) debug("No result for $token!");
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->msqur->db->dbError($e);
		}
		return -1;
	}

	private function getUserIdFromSid($sid)
	{
		if (!$this->msqur->db->connect()) return -1;
		try
		{
			$st = $this->msqur->db->db->prepare("SELECT session_user_id FROM phpbb_sessions WHERE session_id = :sid LIMIT 1");
			DB::tryBind($st, ":sid", $sid);
			$st->execute();
			if ($st->rowCount() > 0)
			{
				$result = $st->fetch(PDO::FETCH_ASSOC);
				$user_id = $result['session_user_id'];
				$st->closeCursor();
				return $user_id;
			}
			else
			{
				if (DEBUG) debug("No result for $sid!");
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->msqur->db->dbError($e);
		}
		return -1;
	}

	public function getUserNameFromId($user_id)
	{
		if (!$this->msqur->db->connect()) return FALSE;
		try
		{
			$st = $this->msqur->db->db->prepare("SELECT username FROM phpbb_users WHERE user_id = :user_id LIMIT 1");
			DB::tryBind($st, ":user_id", $user_id);
			$st->execute();
			if ($st->rowCount() > 0)
			{
				$result = $st->fetch(PDO::FETCH_ASSOC);
				$username = $result['username'];
				$st->closeCursor();
				return $username;
			}
			else
			{
				if (DEBUG) debug("No result for $user_id");
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->msqur->db->dbError($e);
		}
		return FALSE;
	}

	public function getUserProfileLinkFromId($id)
	{
		return "/forum/memberlist.php?mode=viewprofile&u=" . $id;
	}

	public function preprocessTune()
	{
		$more = " <a target='_blank' href='https://github.com/rusefi/rusefi/wiki/HOWTO_upload_tune'>More...</a>";

		if ($this->userid < 0)
			return array("text"=>"Cannot check your tune. Please authorize!", "status"=>"deny");
			
		$einfo = parseQueryString("einfo");
		if (!isset($einfo["name"]) || !isset($einfo["make"]) || !isset($einfo["code"]))
			return array("text"=>"Wrong data!", "status"=>"deny");
		if ($einfo["name"][1] == "" || $einfo["make"][1] == "" || $einfo["code"][1] == "")
			return array("text"=>"Vehicle Name, Engine Make and Code may not be empty! Please check your Tune settings!" . $more, "status"=>"deny");

		$optparams = array("displacement", "compression", "induction");

		// ok, the user data seems valid, let's check his existing engines...

		$engines = $this->msqur->db->getUserVehicles($this->userid);
		$engines = ($engines === FALSE) ? array() : $engines;
		
		$ret = array("text"=>"", "status"=>"warn");
		
		if (count($engines) < 1)
			$ret["text"] = "You are going to upload your first rusEFI Tune! Please check carefully all the info above!". $more;
		else
		{
			$isNewName = true;
			$isNewMakeCode = true;
			$isNewEngineParams = true;
			// check if the user already has that make or code
			foreach ($engines as $e)
			{
				if ($this->areValuesEqual($e["name"], $einfo["name"][1]))
				{
					$isNewName = false;
					if ($this->areValuesEqual($e["make"], $einfo["make"][1]) && $this->areValuesEqual($e["code"], $einfo["code"][1]))
					{
						$isNewMakeCode = false;
						$isNewEngineParams = false;
						foreach ($optparams as $p)
						{
							if (!$this->areValuesEqual($e[$p], $einfo[$p][1]))
							{
								$whatIsDifferent[] = $p;
								$isNewEngineParams = true;
							}
						}
					}
				}
			}

			if ($isNewName)
			{
				$ret["text"] = "This tune is for a new Vehicle! Are you sure you want to add it? Please check carefully the info above!". $more;
			}
			else if ($isNewMakeCode)
			{
				$ret["text"] = "The Engine Make or Code is different in this tune from the stored for your vehicle! Are you sure you want to update them? Please check carefully the info above!". $more;
			}
			else if ($isNewEngineParams)
			{
				$ret["text"] = "The new '".implode(",", $whatIsDifferent)."' info detected for your stored vehicle! Are you sure you want to update the vehicle data?". $more;
			}
		}
		
		// check if the user filled in all the data
		$notAllParams = false;
		foreach ($optparams as $p)
		{
			if ($einfo[$p][1] == "")
				$notAllParams = true;
		}

		if ($notAllParams)
		{
			$ret["text"] .= "<div class=warn2><em>Warning! Your vehicle info is incomplete!</em></div>";
		}

		// no warnings?
		if ($ret["text"] == "")
		{
			$ret["text"] = "Your tune seems OK!";
			$ret["status"] = "ok";
		}
		
		return $ret;
	}

	private function parseLogData($data, $type, $fullSize, $getDataPoints)
	{
		global $logValues;

		$mlgParser = new MlgParser();
		if ($type == -1)
			$type = $mlgParser->detectFormat($data);
		$mlgParser->initStats(false, $getDataPoints);
		if ($type == "LogBinary")
			$ret = $mlgParser->parseBinary($data);
		else if ($type == "LogText")
			$ret = $mlgParser->parseText($data);
		else
			$ret = array("text"=>"Unknown log type!", "status"=>"deny");

		// fill info
		if ($ret["status"] != "deny") {
			$logValues = $mlgParser->getStats($fullSize);
			$ret["logValues"] = $logValues;
			if ($getDataPoints) {
				$dataPoints = $mlgParser->getDataPoints();
				$tunes = $mlgParser->getTunes();
				$ret["dataPoints"] = $dataPoints;
				$ret["tunes"] = $tunes;
			} else {
				$ret["dataPoints"] = array();
				$ret["tunes"] = array();
			}
			$ret["logFields"] = "<small>".$this->fillLogFields()."</small>";
		} else {
			$ret["logValues"] = array();
			$ret["dataPoints"] = array();
			$ret["tunes"] = array();
			$ret["logFields"] = "";
		}

		return $ret;
	}


	public function preprocessLog()
	{
		global $_FILES, $_POST;

		if ($this->userid < 0)
			return array("text"=>"Cannot check your log. Please authorize!", "status"=>"deny");

		//return array("text"=>print_r($_FILES["log"], true), "status"=>"deny");

		if (!isset($_FILES["log"])) {
			return array("text"=>"Cannot send the file! (The size limit is " . ini_get('post_max_size') . " compressed)", "status"=>"deny");
		}
		$logFile = $_FILES["log"];
		if ($logFile["error"] == 1) {
			return array("text"=>"The file is too big! The max size is ".ini_get('upload_max_filesize'), "status"=>"deny");
		}
		
		$zdata = isset($logFile['tmp_name']) ? @file_get_contents($logFile['tmp_name']) : FALSE;
		if ($zdata === FALSE) {
			return array("text"=>"Cannot read the compressed log data!", "status"=>"deny");
		}

		$data = @zlib_decode($zdata);
		if ($data === FALSE) {
			return array("text"=>"Cannot decompress the log data!", "status"=>"deny");
		}

		//file_put_contents("log", $data);

		$type = parseQueryString("type");
		$fullSize = parseQueryString("fullSize");
		$ret = $this->parseLogData($data, $type, $fullSize, false);

		return $ret;
	}

	public function getLogInfo($data, &$dataPoints, &$tunes) {
		$fullSize = strlen($data);
		$ret = $this->parseLogData($data, -1, $fullSize, true);
		$dataPoints = $ret["dataPoints"];
		$tunes = $ret["tunes"];
		// store the array as a JSON string
		return json_encode($ret["logValues"]);
	}

	public function fillLogFields() {
		global $logValues, $rusefi;
		ob_start();
		include_once "view/logfields.php";
		return ob_get_clean();
	}

	public function fillGeneralLogInfo() {
		global $logValues;
		global $rusefi;
		ob_start();
		include "view/loggeneral.php";
		return ob_get_clean();
	}

	public function processValue($v)
	{
		$v = trim($v);

		if (strcasecmp($v, "true") == 0)
			$v = "1";
		else if (strcasecmp($v, "false") == 0)
			$v = "0";
		else if (is_numeric($v))
			$v = round(floatval($v), 3);

		return $v;
	}

	public function areValuesEqual($v1, $v2)
	{
		$v1 = $this->processValue($v1);
		$v2 = $this->processValue($v2);
		return strcmp($v1, $v2) == 0;
	}

	public function checkIfTuneCanBeUploaded($files, $engineid, &$err_msg)
	{
		foreach ($files as $file)
		{
			$id = $this->msqur->db->findMSQ($file, $engineid);
			if ($id === FALSE) {
				$err_msg = "Cannot upload this tune! The reasons are:<br>" . get_all_error_messages();
				return FALSE;
			}
			if ($id > 0) {
				$err_msg = "This tune already exists in the database. <a href=\"view.php?msq=$id\">View tune #$id</a>.";
				return FALSE;
			}
		}
		return TRUE;
	}

	public function getUserTunes($user_id) {
		$list = $this->msqur->db->getUserTunes($user_id);
		foreach ($list as &$l) {
			$l = $l["uploadDate"]
				. " " . implode(" ", array($l["make"], $l["code"], "\"" . $l["name"] . "\""))
				. " (" . $l["numCylinders"] . "cyl"
				. " " . $l["displacement"] . "L)";
		}
		return $list;
	}

	public function checkIfTuneIsValidForUser($user_id, $tune_id)
	{
		$tunes = $this->getUserTunes($user_id);
		return isset($tunes[$tune_id]);
	}

	public function unpackLogInfo(&$results, $skipDataPoints = false)
	{
		if (!is_array($results))
			return;
		$mlgParser = new MlgParser();
		foreach ($results as &$r)
		{
			$info = json_decode($r["info"]);
			if (is_object($info))
				$info = get_object_vars($info);
			$r = array_merge($r, $info);
			if (!$skipDataPoints) {
				if ($r["data"] == NULL) {
					$this->msqur->db->updateLogDataPoints($r["mid"]);
				}
				$r["data"] = $mlgParser->unpackDataPoints($r["data"]);
			}
		}
		return $results;
	}

	public function getDurationInSeconds($dur)
	{
		$secs = 0;
		if (preg_match("/([0-9]+)\s*h/", $dur, $ret)) {
			$secs += $ret[1] * 3600;
		}
		if (preg_match("/([0-9]+)\s*min/", $dur, $ret)) {
			$secs += $ret[1] * 60;
		}
		if (preg_match("/([0-9\.]+)\s*sec/", $dur, $ret)) {
			$secs += $ret[1];
		}
		return $secs;
	}

	public function viewLog($id, &$engine)
	{
		global $logValues;
		$res = $this->msqur->db->browseLog(array("l.id"=>$id));
		$this->unpackLogInfo($res);
		$logValues = $res[0];

		$this->msqur->db->updateLogViews($id);
		
		$engine = $this->getEngineFromTune($logValues["tune_id"]);
		$moreInfo = "";
		if ($engine) {
			ob_start();
			include "view/more_about_vehicle.php";
			$moreInfo = ob_get_clean();
		}
		
		$notes = $this->msqur->db->getLogNotes($id);
		$logValues["notes"] = $notes;

		return "<div class=logViewPage>".$moreInfo.$this->fillGeneralLogInfo().$this->fillLogFields()."</div>";
	}

	public function getLogForDownload($id)
	{
		return $this->msqur->db->getLog($id);
	}

	public function viewTs($id, $options)
	{
		$this->msq = new MSQ();
		return $this->getTs($this->msq, $id, $options, "ts");
	}

	public function getTs(&$msq, $id, $options, $viewType = "ts")
	{
		$xml = $this->msqur->db->getXML($id);
		if ($xml === null) 
		{
			error("Null xml");
			return "";
		}

		$html = "";
		try {
			
			$engine = $this->getEngineFromTune($id);
			$metadata = array();
			$groupedHtml = $msq->parseMSQ($xml, $engine, $metadata, $viewType, $options);
						
			//$this->db->updateMetadata($id, $metadata);
			//$this->db->updateEngine($id, $engine, $metadata);

			foreach($groupedHtml as $group => $v)
			{
				$html .= $v;
			}

		} catch (MSQ_ParseException $e) {
			$html = $e->getHTMLMessage();
		} finally {
			return $html;
		}
	}

	public function getOptions()
	{
		if (isset($this->msq->msqMap["settingGroup"]))
			return $this->msq->msqMap["settingGroup"];
		return INI::getDefaultSettingGroups();
	}

	public function getTuneList($vehicleName)
	{
		$list = $this->msqur->db->getUserTunes($this->userid, $vehicleName);
		return $list;
	}

	public function getEngineFromTune($tuneId)
	{
		return $this->msqur->db->getEngineFromTune($tuneId);
	}

	public function getForumTopicId($user_id, $vehicleName)
	{
		return $this->msqur->db->getForumTopicId($user_id, $vehicleName);
	}

	public function getMsqConstant($c)
	{
		return $this->getMsqConstantFull($c, $this->msq, $digits);
	}

	public function getMsqConstantFull($c, $msq, &$digits)
	{
		$cc = $msq->findConstant($msq->msq, $c, $digits, false);
		if ($cc === NULL)
			return NULL;
		$value = trim($cc, " \r\n\"");
		// post-process the value
		if (isset($msq->msqMap["Constants"][$c])) {
			$cons = $msq->msqMap["Constants"][$c];
			if ($cons[0] == "bits") {
				$options = array_slice($cons, 4);
				$idx = array_search($value, $options, TRUE);
				if ($idx !== FALSE) {
					$value = $idx;
				}
			}
			$value = explode("\n", $value);
			if ($cons[0] == "array") {
				// get 1D array
				$arr = array();
				foreach ($value as $row) {
					$r = explode(" ", trim($row));
					$arr = array_merge($arr, $r);
				}
				$value = $arr;
				$numeric = true;
			} else if ($cons[0] == "scalar") {
				$numeric = true;
			} else {
				$numeric = false;
			}

			if ($numeric) {
				// unfortunately libxml's thousand separators are unpredictable and OS-dependent
				$value = array_map(function($v) { return floatval(str_replace(',', '', $v)); }, $value);
			}
			if (count($value) == 1)
				return $value[0];
		}
		return $value;
	}

	public function roundMsqConstant($c, $digits)
	{
		if (is_array($c)) {
			return array_map(function($v) use ($digits) { return round($v, $digits); }, $c);
		}
		return round($c, $digits);
	}
	
	public function getMsqOutput($o)
	{
		if (isset($this->msq->msqMap["outputs"][$o])) {
			$o = $this->msq->msqMap["outputs"][$o];
			try
			{
				// see INI::parseExpression()
				$value = eval($o);
				return $value;
			} catch (Throwable $t) {
				// todo: should we react somehow?
			}

		}
		return "";
	}

	public function changeTuneNote($tune_id, $tuneNote)
	{
		$engine = $this->getEngineFromTune($tune_id);
		if (empty($engine)) {
			return array("status"=>"deny", "text"=>"The tune was not found!");
		}
		$isOwner = isset($engine["user_id"]) && ($engine["user_id"] == $this->userid);
		if (!$isOwner) {
			return array("status"=>"deny", "text"=>"Only the owner can change the tune note!");
		}

		if ($this->msqur->db->changeTuneNote($tune_id, $tuneNote)) {
			return array("status"=>"ok", "text"=>"");
		}
	}

	public function calcCrcForTune($xml)
	{
		$engine = array();
		$metadata = array();
		$settings = array();
		$msq = new MSQ(); //ugh
		try {
			$groupedHtml = $msq->parseMSQ($xml, $engine, $metadata, "crc", $settings);
		} catch (MSQ_ParseException $e) {
			return FALSE;
		}
		return $this->calcCrc($msq);
	}

	public function calcCrc($msq, $skipFields = array("warning_message"))
	{
		if (!isset($msq->msqMap["Constants"]))
			return 0;
		// Engine::preCalculate() hashes persistent_config_s (INI page 1) only.
		// pageSize is a scalar for old INIs and a list for multi-page INIs.
		$pageSizes = (array)$msq->msqMap["Constants"]["pageSize"];
		$pageData = array_fill(0, (int)$pageSizes[0], 0);
		// INI expressions call $rusefi getters. Bind them to this tune locally.
		$rusefi = clone $this;
		$rusefi->msq = $msq;
		foreach ($msq->msqMap["Constants"] as $consName=>$cons) {
			if (($msq->msqMap["constantPages"][$consName] ?? null) !== 1)
				continue;
			if (in_array($consName, $skipFields))
				continue;

			$value = $this->getMsqConstantFull($consName, $msq, $digits);
			if ($value === NULL)
				continue;
			$offset = $cons[2];
			$numBytes = 1;
			$numElements = 1;
			if (preg_match("/[FSU]([0-9]+)/", $cons[1], $ret)) {
				$numBytes = intval($ret[1]) / 8;
			}
			if ($cons[0] == "array" || $cons[0] == "scalar") {
				$scale = $cons[$cons[0] == "array" ? 5 : 4];
				if (strpos($scale, "{") === 0) {
					$scale = eval(INI::parseExpression($scale, $msq->msq, array()));
				}
				// bcmath requires decimal strings, not scientific notation.
				if (preg_match('/^([+-]?[0-9.]+)[eE]([+-]?[0-9]+)$/', (string)$scale, $ret)) {
					$scale = bcmul($ret[1], bcpow("10", (int)$ret[2], 100), 100);
				}
			}

			// write the value
			if ($cons[0] == "bits") {
				// read the existing value
				$v = 0;
				for ($i = 0; $i < $numBytes; $i++) {
					$v |= intval($pageData[$offset + $i]) << ($i * 8);
				}
				// apply the value as a bitmask
				if (preg_match("/\[([0-9]+)\:([0-9]+)\]/", $cons[3], $ret)) {
					$startBit = $ret[1];
					$numBits = $ret[2] - $startBit + 1;
					$value = intval($value);
					// create a bitmask
					$val = ($value & ((1 << $numBits) - 1)) << $startBit;
					// we assume that bits don't overlap, so a simple 'or' should be enough
					$value = $v | $val;
				}
			} else if ($cons[0] == "array") {
				if (preg_match("/\[([0-9]+)x?([0-9]+)?\]/", $cons[3], $ret)) {
					$numElements = $ret[1];
					if (isset($ret[2]))
						$numElements *= $ret[2];
				}
				// scale (we use bcdiv because we want a precise java-compliant float division)
				$value = array_map(function($v) use ($scale) { return bcdiv($v, $scale, 100); }, $value);
			} else if ($cons[0] == "scalar") {
				// scale
				$value = bcdiv($value, $scale, 100);
			} else if ($cons[0] == "string") {
				$numBytes = $cons[3];
			}

			if ($numElements == 1 && !is_array($value)) {
				$value = array($value);
			}

			// save the values
			$fields = array("U08"=>"C", "S08"=>"c", "U16"=>"S", "S16"=>"s", "U32"=>"L", "S32"=> "l", "F32"=>"f", "ASCII"=>"a".$numBytes);
			for ($i = 0; $i < $numElements; $i++) {
				// repack the data into the byte array
				$bin = pack($fields[$cons[1]], $value[$i]);
				$v = unpack("C*", $bin);

				// store the data byte by byte
				$off = $offset + $i * $numBytes;
				for ($j = 0; $j < $numBytes; $j++) {
					// unpack() uses 1-based indices
					$pageData[$off + $j] = $v[$j + 1];
				}
			}
		}
		
		$data = implode(array_map("chr", $pageData));

        $crc32 = crc32($data);
        $crc16 = $crc32 & 0xFFFF;
        //!!!!!!!!!
        //echo "crc32=".dechex($crc32). " crc16=".dechex($crc16). "\r\n";
		//file_put_contents("current_configuration.rusefi_binary", "OPEN_SR5_0.1" . $data);
		//file_put_contents("current_configuration.xml.txt", print_r($msq, TRUE));

		return $crc16;
	}

	public function getTuneLogs($tuneId)
	{
		return $this->msqur->db->getTuneLogs($tuneId);
	}

}

$rusefi = new Rusefi($msqur);

?>
