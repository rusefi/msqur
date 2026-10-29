<?php
/* msqur - MegaSquirt .msq file viewer web application
Copyright 2014-2019 Nicholas Earwood nearwood@gmail.com https://nearwood.dev

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>. */

/**
 * @brief DB handling stuff.
 * 
 */
class DB
{
	public $db;

	private $logFilesFolder = "logs/";

	public $COMPRESS = "COMPRESS";
	public $UNCOMPRESS = "UNCOMPRESS";
	
	private $isSqlite = false;
	
	public function connect()
	{
		if (isset($this->db) && $this->db instanceof PDO)
		{
			//if (DEBUG) debug("Reusing DB connection.");
		}
		else if (!empty(DB_HOST))
		{
			try
			{
				//if (DEBUG) debug('Connecting to DB: ' . "mysql:dbname=" . DB_NAME . ";host=" . DB_HOST . "," . DB_USERNAME . ", [****]");
				$this->db = new PDO("mysql:dbname=" . DB_NAME . ";host=" . DB_HOST, DB_USERNAME, DB_PASSWORD);
				//Persistent connection:
				//$this->db = new PDO("mysql:dbname=" . DB_NAME . ";host=" . DB_HOST, DB_USERNAME, DB_PASSWORD, array(PDO::ATTR_PERSISTENT => true);
			}
			catch (PDOException $e)
			{
				error("Could not connect to database");
				echo '<div class="error">Error connecting to database.</div>';
				$this->dbError($e);
				$this->db = null; //Redundant.
			}
		}

		$this->isSqlite = ($this->db != null) && ($this->db instanceof PDO) && ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) == "sqlite");

		//if (DEBUG) debug('Connecting to DB: ' . (($this->db != null) ? 'Connected.' : 'Connection FAILED'));
		return ($this->db != null);
	}
	
	function __construct()
	{
		$this->connect();
	} 
	
	/**
	 * @brief Add a new MSQ to the DB
	 * @param $file The uploaded file
	 * @param $engineid String The ID of the engine metadata
	 * @returns the ID of the new engine record, or null if unsuccessful.
	 */
	public function addMSQ($file, $engineid)
	{
		global $rusefi;

		if (!$this->connect()) return null;
		
		try
		{
			//TODO transaction so we can rollback (`$db->beginTransaction()`)
			$st = $this->db->prepare("INSERT INTO msqur_files (type,crc,data) VALUES (:type, :crc, ".$this->COMPRESS."(:xml))");
			$xml = file_get_contents($file['tmp_name']);
			//Convert encoding to UTF-8
			$xml = mb_convert_encoding($xml, "UTF-8");
			//Strip out invalid xmlns
			//$xml = preg_replace('/xmlns=".*?"/', '', $xml);
			// [andreika]: get date&comment and remove this data from the XML data
			if (preg_match("/<bibliography(.*?)(author\s*=\s*\"([^\"]*)\"?)\s+(tuneComment\s*=\s*\"([^\"]*)\"?)\s+writeDate\s*=\s*\"([^\"]+)\"\s*\/>/", $xml, $bib)) {
				$author = $bib[3];
				$comment = $bib[5];
				$comment = preg_replace("/^&lt;html&gt;/", "", $comment);

				$wdt = strtotime($bib[6]);
			} else {
				$author = "";
				$comment = "";
				$wdt = 0;
			}
			$xml = preg_replace("/<bibliography[^\/]*\/>/", "", $xml);
			$xml = trim($xml);

			// get CRC
			$crc16 = $rusefi->calcCrcForTune($xml);
			if ($crc16 === FALSE) {
				error("Cannot calculate CRC for this tune!");
				return -1;
			}
			
			DB::tryBind($st, ":type", 0);
			DB::tryBind($st, ":xml", $xml);
			DB::tryBind($st, ":crc", $crc16);
			if ($st->execute())
			{
				$id = $this->db->lastInsertId();
				$st = $this->db->prepare("INSERT INTO msqur_metadata (url,file,engine,fileFormat,signature,author,uploadDate,writeDate,tuneComment) VALUES (:url, :id, :engine, '4.0', 'unknown', :author, :uploaded, :writeDate, :tuneComment)");
				DB::tryBind($st, ":url", $id); //could do hash but for now, just the id
				DB::tryBind($st, ":id", $id);
				if (!is_numeric($engineid)) $engineid = null;
				DB::tryBind($st, ":engine", $engineid);
				//TODO Make sure it's an int
				$dt = new DateTime();
				$dt = $dt->format('Y-m-d H:i:s');
				DB::tryBind($st, ":uploaded", $dt);
				// [andreika]: fill-in additional fields removed from the xml
				$wdt = ($wdt != 0) ? date("Y-m-d H:i:s", $wdt) : $dt;
				DB::tryBind($st, ":writeDate", $wdt);
				DB::tryBind($st, ":tuneComment", $comment);
				DB::tryBind($st, ":author", $author);

				if ($st->execute()) {
					$id = $this->db->lastInsertId();
				} else {
					error("Error inserting metadata");
					if (DEBUG) {
						print_r($st->errorInfo());
					}
					$id = -1;
				}
				$st->closeCursor();
			} else {
				error("Error inserting XML data");
				if (DEBUG) {
					print_r($st->errorInfo());
				}
				$id = -1;
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
			$id = -1;
		}
		
		return $id;
	}
	
	/**
	 * @brief Add a new engine to the DB
	 * @param $make String The engine make (Nissan)
	 * @param $code String the engine code (VG30)
	 * @param $displacement decimal in liters
	 * @param $compression decimal The X from X:1 compression ratio
	 * @param $turbo boolean Forced induction
	 * @returns the ID of the new engine record, or null if unsuccessful.
	 */
	public function addOrUpdateVehicle($user_id, $name, $make, $code, $displacement, $compression, $induction)
	{
		$id = null;
		
		if ($name == NULL) $name = "";
		if ($make == NULL) $make = "";
		if ($code == NULL) $code = "";
		
		if (!is_numeric($displacement) || !is_numeric($compression))
			echo '<div class="error">Invalid engine configuration.</div>';
		else
		{
			if (!$this->connect()) return null;
			
			try
			{
				$where = "WHERE user_id = :user_id AND name = :name";
				$st = $this->db->prepare("SELECT id FROM msqur_engines " . $where);
				DB::tryBind($st, ":user_id", $user_id);
				DB::tryBind($st, ":name", $name);
				$st->execute();
				$result = $st->fetch(PDO::FETCH_ASSOC);
				if ($result && count($result) > 0)
				{
					$update_id = $result['id'];

					if (DEBUG) debug("Update vehicle: \"$user_id\", \"$name\", \"$make\", \"$code\", $displacement, $compression, $induction");
	                $st = $this->db->prepare("UPDATE msqur_engines SET make = :make, code = :code, displacement = :displacement, compression = :compression, induction = :induction " . $where);
	            } else
	            {
					if (DEBUG) debug("Add vehicle: \"$user_id\", \"$make\", \"$code\", $displacement, $compression, $induction");
					$st = $this->db->prepare("INSERT INTO msqur_engines (user_id, name, make, code, displacement, compression, induction) VALUES (:user_id, :name, :make, :code, :displacement, :compression, :induction)");
					$update_id = -1;
				}
				
				DB::tryBind($st, ":user_id", $user_id);
				DB::tryBind($st, ":name", $name);
				DB::tryBind($st, ":make", $make);
				DB::tryBind($st, ":code", $code);
				DB::tryBind($st, ":displacement", $displacement);
				DB::tryBind($st, ":compression", $compression);
				DB::tryBind($st, ":induction", $induction);
				
				if ($st->execute()) 
				{
					$id = $update_id > 0 ? $update_id : $this->db->lastInsertId();
				}
				else echo "<div class=\"error\">Error adding engine: \"$make\", \"$code\"</div>";
				$st->closeCursor();
			}
			catch (PDOException $e)
			{
				$this->dbError($e);
			}
		}
		
		if (DEBUG) debug("Add engine returns: $id");
		return $id;
	}
	
	/**
	 * @brief Whether the reingest flag is set or not for the given id
	 * @param $id The metadata id
	 * @returns TRUE if reingest flag is set to 1, FALSE if 0
	 */
	public function needReingest($id)
	{
		if (!$this->connect()) return FALSE;
		
		try
		{
			$st = $this->db->prepare("SELECT reingest FROM msqur_metadata WHERE msqur_metadata.id = :id LIMIT 1");
			DB::tryBind($st, ":id", $id);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$reingest = $result['reingest'];
				$st->closeCursor();
				return $reingest;
			}
			else
			{
				if (DEBUG) debug("No result for $id");
				echo '<div class="error">Invalid MSQ</div>';
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return FALSE;
	}
	
	/**
	 * @brief Reset regingest flag.
	 * @param $id The metadata id
	 * @returns true if successful, false otherwise
	 */
	public function resetReingest($id)
	{
		if (!$this->connect()) return false;
		
		try
		{
			if (DEBUG) debug('Resetting reingest flag...');
			$st = $this->db->prepare("UPDATE msqur_metadata m SET m.reingest=FALSE WHERE m.id = :id");
			DB::tryBind($st, ":id", $id);
			if ($st->execute())
			{
				if (DEBUG) debug('Reingest reset.');
				$st->closeCursor();
				return true;
			}
			else
				if (DEBUG) debug('Unable to update cache.');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}
	
	/**
	 * @brief Get MSQ HTML from metadata id
	 * @param $id The metadata id
	 * @returns FALSE if not cached, null if not found, otherwise the HTML.
	 */
	public function getCachedMSQ($id)
	{
		if (DISABLE_MSQ_CACHE)
		{
			if (DEBUG) debug('Cache disabled.');
			return null;
		}
		
		if ($this->needReingest($id))
		{
			if (DEBUG) debug('Flagged for reingest.');
			$this->resetReingest($id);
			return null;
		}
		
		if (!$this->connect()) return null;
		
		$html = null;
		
		try
		{
			$st = $this->db->prepare("SELECT html FROM msqur_files INNER JOIN msqur_metadata ON msqur_metadata.file = msqur_files.id WHERE msqur_metadata.id = :id LIMIT 1");
			DB::tryBind($st, ":id", $id);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$st->closeCursor();
				$html = $result['html'];
				if ($html === NULL)
				{
					if (DEBUG) debug('No HTML cache found.');
					return null;
				}
				else if (DEBUG) debug('Cached, returning HTML.');
			}
			else
			{
				if (DEBUG) debug("No result for $id");
				return FALSE;
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return $html;
	}
	
	/**
	 * @brief Get a list of MSQs
	 * @param $bq The BrowseQuery to filter results
	 * @returns A list of metadata, or null if unsuccessful
	 */
	public function browseMsq($bq, $showAll, $showHidden)
	{
		if (!$this->connect()) return null;

		try
		{
			// MySql and SQLite have different CONCAT syntax
			$concat = $this->isSqlite ? "(user_id || '_' || name)" : "CONCAT (user_id, '_', name)";

			$statement = "SELECT ".$concat." AS vehicleName, m.id as mid, user_id, name, make, code, numCylinders, displacement, compression, induction, firmware, signature, uploadDate, views, tuneComment, hidden FROM msqur_metadata m INNER JOIN msqur_engines e ON m.engine = e.id WHERE ";
			$where = array();
			foreach ($bq as $col => $v)
			{
				//if ($v !== null) $statement .= "$col = :$col ";
				if ($v !== null) $where[] = "$col = :".str_replace(".", "", $col)." ";
			}

			if (!$showHidden)
				$where[] = "hidden = 0";
			
			if (count($where) === 0) $statement .= "1";
			else
			{
				$statement .= "(" . implode(" AND ", $where) . ")";
			}

			if (!$showAll)
				$statement .= " GROUP BY vehicleName";

			$statement .= " ORDER BY mid DESC";
			
			//echo $statement;
			
			$st = $this->db->prepare($statement);
			
			foreach ($bq as $col => $v)
			{
				if ($v !== null) $this->tryBind($st, ":".str_replace(".", "", $col), $v);
			}
			
			if ($st->execute())
			{
				$result = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $result;
			}
			else echo '<div class="error">There was a problem constructing the browse query: '.print_r($st->errorInfo(), TRUE).'</div>'; //var_export($st->errorInfo())
			
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return null;
	}
	
	/**
	 * @brief Search metadata for any hits against a search query
	 * @param $query The string to search against
	 * @returns A list of matching metadata, or null if unsuccessful
	 */
	public function search($query)
	{
		if (!$this->connect()) return null;
		//tuneComment, uploadDate writeDate author firmware signature e.make e.code e.displacement e.compression e.numCylinders
		//firmware signature e.make e.code e.displacement e.compression e.numCylinders
		try
		{
			$st = $this->db->prepare("SELECT m.id as mid, name, make, code, numCylinders, displacement, compression, induction, firmware, signature, uploadDate, views, tuneComment FROM msqur_metadata m INNER JOIN msqur_engines e ON m.engine = e.id WHERE firmware LIKE :query");
			DB::tryBind($st, ":query", "%" . $query . "%"); //TODO exact/wildcard option
			if ($st->execute())
			{
				$result = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $result;
			}
			else echo '<div class="error">There was a problem constructing the search query.</div>';
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return null;
	}
	
	/**
	 * @brief Get all unique firmware names listed in DB
	 * @returns List of strings
	 */
	public function getFirmwareList()
	{
		if (!$this->connect()) return null;
			
		try
		{
			if (DEBUG) debug("Getting firmware list...");
			$st = $this->db->prepare("SELECT DISTINCT firmware FROM `msqur_metadata`");
			
			if ($st->execute())
			{
				$ret = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $ret;
			}
			else echo "<div class=\"error\">Error getting firmware list</div>";
			
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
	}
	
	/**
	 * @brief Get unique firmware versions listed in DB
	 * @param $firmware name of firmware to limit versions to
	 * @returns List of strings
	 */
	public function getFirmwareVersionList($firmware = null)
	{
		if (!$this->connect()) return null;
		
		try
		{
			if (DEBUG) debug("Getting firmware version list...");
			if ($firmware == null)
			{
				$st = $this->db->prepare("SELECT DISTINCT signature FROM `msqur_metadata`");
			}
			else
			{
				$st = $this->db->prepare("SELECT DISTINCT signature FROM `msqur_metadata` WHERE firmware = :fw");
				DB::tryBind($st, ":fw", $firmware);
			}
			
			if ($st->execute())
			{
				$ret = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $ret;
			}
			else echo "<div class=\"error\">Error getting firmware version list for: $firmware</div>";
			
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
	}
	
	public function getEngineMakeList()
	{
		if (!$this->connect()) return null;
			
		try
		{
			if (DEBUG) debug("Getting engine make list...");
			$st = $this->db->prepare("SELECT DISTINCT make FROM `msqur_engines`");
			
			if ($st->execute())
			{
				$ret = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $ret;
			}
			else echo "<div class=\"error\">Error getting engine make list</div>";
			
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
	}
	
	public function getEngineCodeList($make = null)
	{
		if (!$this->connect()) return null;
			
		try
		{
			if (DEBUG) debug("Getting engine code list...");
			
			if ($make !== null && gettype($make) == "string")
			{
				$st = $this->db->prepare("SELECT DISTINCT code FROM `msqur_engines` WHERE make = :make");
				DB::tryBind($st, ":make", $make);
			}
			else
			{
				$st = $this->db->prepare("SELECT DISTINCT code FROM `msqur_engines`");
			}
			
			if ($st->execute())
			{
				$ret = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $ret;
			}
			else echo "<div class=\"error\">Error getting engine code list</div>";
			
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
	}
	
	/**
	 * @brief Update HTML cache of MSQ by metadata id
	 * @param $id integer The ID of the metadata.
	 * @param $html String HTML string of the shit to update.
	 * @returns TRUE or FALSE depending on success.
	 */
	public function updateCache($id, $html)
	{
		if (!$this->connect()) return false;
		
		try
		{
			if (DEBUG) debug('Updating HTML cache...');
			$st = $this->db->prepare("UPDATE msqur_files SET html = :html WHERE id = (SELECT file FROM msqur_metadata WHERE id = :id)");
			//$xml = mb_convert_encoding($html, "UTF-8");
			DB::tryBind($st, ":id", $id);
			DB::tryBind($st, ":html", $html);
			if ($st->execute())
			{
				if (DEBUG) debug('Cache updated.');
				$st->closeCursor();
				return true;
			}
			else
				if (DEBUG) debug('Unable to update cache.');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}
	
	/**
	 * @brief Update engine with extra data.
	 * This is used after parsing a MSQ and getting additional engine information (injector size, number of cylinders, etc.)
	 * @param $id integer The ID of the engine.
	 * @param $engine array The associative array of new engine data.
	 * @returns TRUE or FALSE depending on success.
	 */
	public function updateEngine($id, $engine, $metadata)
	{
		if (!$this->connect()) return false;
		
		$reqFields = getEngineDbRequiredKeys($metadata);
		if (!array_keys_exist($engine, ...$reqFields))
		{//Some MSQs seem to be missing the injType
			$fieldsNotFound = array();
			foreach ($reqFields as $r) {
				if (!array_key_exists($r, $engine)) {
					$fieldsNotFound[] = $r;
				}
			}
			echo '<div class="warn">WARNING! Incomplete engine information. Could not find the following field(s): ' . implode(',', $fieldsNotFound) . '.<br>Unable to update engine metadata.</div>';

			//var_export($engine);
			return false;
		}
		
		try
		{
			if (DEBUG) debug('Updating engine information...');

			$setFields = array();
			// nCylinders, twoStroke, injType, nInjectors, engineType
			foreach ($engine as $k=>$e)
			{
				$setFields[] = $k . " = :" . $k;
			}

			$st = $this->db->prepare("UPDATE msqur_engines SET " . implode(", ", $setFields) . " WHERE id = (SELECT engine FROM msqur_metadata WHERE id = :id)");
			DB::tryBind($st, ":id", $id);

			foreach ($engine as $k=>$e)
			{
				DB::tryBind($st, ":" . $k, $e);
			}
			
			$defFields = getEngineDbDefaultKeys($metadata);
			foreach ($defFields as $dfField=>$dfValue)
			{
				if (!array_key_exists($dfField, $engine))
					DB::tryBind($st, ":".$dfField, $dfValue);
			}
			
			if ($st->execute())
			{
				if (DEBUG) debug('Engine updated.');
				$st->closeCursor();
				return true;
			}
			else
				if (DEBUG) debug('Unable to update engine metadata.');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}
	
	/**
	 * @brief Update metadata with extra information.
	 * This is used after parsing a MSQ and getting additional information (firmware version, etc.)
	 * @param $id integer The ID of the metadata.
	 * @param $metadata array The associative array of extra metadata.
	 * @returns TRUE or FALSE depending on success.
	 */
	public function updateMetadata($id, $metadata)
	{
		if (!$this->connect()) return false;
		
		if (!array_keys_exist($metadata, 'fileFormat', 'signature', 'firmware'))
		{
			if (DEBUG) debug('Invalid MSQ metadata: ' . $metadata);
			echo '<div class="warn">Incomplete MSQ metadata.</div>';
			return false;
		}
		
		try
		{
			if (DEBUG) debug('Updating MSQ metadata...');
			$st = $this->db->prepare("UPDATE msqur_metadata SET fileFormat = :fileFormat, signature = :signature, firmware = :firmware WHERE id = :id");
			//$xml = mb_convert_encoding($html, "UTF-8");
			DB::tryBind($st, ":id", $id);
			DB::tryBind($st, ":fileFormat", $metadata['fileFormat']);
			DB::tryBind($st, ":signature", $metadata['signature']);
			DB::tryBind($st, ":firmware", $metadata['firmware']);
			if ($st->execute())
			{
				if (DEBUG) debug('Metadata updated.');
				$st->closeCursor();
				return true;
			}
			else
				if (DEBUG) debug('Unable to update metadata.');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}
	
	/**
	 * @brief Increment the view count of a metadata record.
	 * @param $id integer The ID of the metadata to update.
	 * @returns TRUE or FALSE depending on success.
	 */
	public function updateViews($id)
	{
		if (!$this->connect()) return false;
		
		try
		{
			$st = $this->db->prepare("UPDATE msqur_metadata SET views = views + 1 WHERE id = :id");
			DB::tryBind($st, ":id", $id);
			$ret = $st->execute();
			$st->closeCursor();
			return $ret;
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}
	
	public static function bindError($e)
	{
		if (DEBUG)
		{
			echo '<div class="error">Error preparing database query:<br/>';
			echo $e;
			echo '</div>';
		}
		else echo '<div class="error">Error preparing database query.</div>';
	}
	
	public static function tryBind($statement, $placeholder, $value)
	{
		//TODO arg check
		if (!$statement->bindParam($placeholder, $value))
		{
			DB::bindError("Error binding: $value to $placeholder");
		}
	}
	
	public function dbError($e)
	{
		if (DEBUG)
		{
			error("DB Error: " . $e->getMessage());
			echo '<div class="error">Error executing database query:<br/>';
			echo $e->getMessage();
			echo '</div>';
		}
		else echo '<div class="error">Error executing database query.</div>';
	}
	
	/**
	 * @brief Get the raw XML of a MSQ
	 * @param $id The ID of the associated metadata
	 * @returns XML String or null if unsuccessful
	 */
	public function getXML($id)
	{
		if (DEBUG) debug('Getting XML for id: ' . $id);
		
		if (!$this->connect()) return null;
		
		$xml = null;
		
		try
		{
			// [andreika]: use compressed data
			$st = $this->db->prepare("SELECT ".$this->UNCOMPRESS."(data) AS xml FROM msqur_files INNER JOIN msqur_metadata ON msqur_metadata.file = msqur_files.id WHERE msqur_metadata.id = :id LIMIT 1");
			DB::tryBind($st, ":id", $id);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				if (DEBUG) debug('XML Found.');
				
				$st->closeCursor();
				$xml = $result['xml'];
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return $xml;
	}

	//////////////////////////////////////////////////////////////////////////////
	// [andreika]: rusEfi
	
	public function getUserVehicles($user_id)
	{
		if (!$this->connect()) return FALSE;
		try
		{
			$st = $this->db->prepare("SELECT name, make, code, displacement, compression, induction FROM msqur_engines WHERE user_id = :user_id");
			DB::tryBind($st, ":user_id", $user_id);
			$st->execute();
			$result = $st->fetchAll(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$st->closeCursor();
				return $result;
			}
			else
			{
				if (DEBUG) debug("No result for $user_id");
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}

		return FALSE;
	}

	public function getUserTunes($user_id, $vehicleName = "")
	{
		if (!$this->connect()) return array();
		try
		{
			$vehicleFilter = !empty($vehicleName) ? "AND e.name = :vehicleName" : "";
			$st = $this->db->prepare("SELECT m.id as mid, name, make, code, numCylinders, displacement, compression, uploadDate FROM msqur_metadata m INNER JOIN msqur_engines e ON m.engine = e.id WHERE (e.user_id = :user_id ".$vehicleFilter.") ORDER BY m.uploadDate DESC");
			DB::tryBind($st, ":user_id", $user_id);
			if (!empty($vehicleName))
				DB::tryBind($st, ":vehicleName", $vehicleName);
			$st->execute();
			$result = $st->fetchAll(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$res = array();
				foreach ($result as $r)
					$res[$r["mid"]] = $r;
				$st->closeCursor();
				return $res;
			}
			else
			{
				$st->closeCursor();
				return array();
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}

		return array();
	}

	public function getEngineFromTune($tune_id)
	{
		if (!$this->connect()) return array();
		try
		{
			$st = $this->db->prepare("SELECT name, make, code, user_id, displacement, compression, induction FROM msqur_engines e INNER JOIN msqur_metadata m ON m.engine = e.id WHERE m.id = :tune_id");
			DB::tryBind($st, ":tune_id", $tune_id);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$st->closeCursor();
				return $result;
			}
			else
			{
				if (DEBUG) debug("No result for $tune_id");
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}

		return array();
	}

	public function getForumTopicId($user_id, $vehicleName)
	{
		if (empty($user_id) || empty($vehicleName))
			return -1;
		if (!$this->connect()) return FALSE;
		try
		{
			$st = $this->db->prepare("SELECT topic_id FROM msqur_engines WHERE (user_id = :user_id AND name= :name)");
			DB::tryBind($st, ":user_id", $user_id);
			DB::tryBind($st, ":name", $vehicleName);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$st->closeCursor();
				return $result["topic_id"];
			}
			else
			{
				if (DEBUG) debug("No result for $tune_id");
				$st->closeCursor();
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}

		return -1;
	}
	
	public function findMSQ($file, $engineid)
	{
		global $rusefi;
		if (!$this->connect()) return FALSE;
		
		try
		{
			//TODO transaction so we can rollback (`$db->beginTransaction()`)
			$st = $this->db->prepare("SELECT m.id, ".$this->UNCOMPRESS."(f.data) AS xml FROM msqur_files f INNER JOIN msqur_metadata m ON m.file = f.id WHERE f.crc = :crc");
			$xml = file_get_contents($file['tmp_name']);
			//Convert encoding to UTF-8
			$xml = mb_convert_encoding($xml, "UTF-8");
			//Strip out invalid xmlns
			//$xml = preg_replace('/xmlns=".*?"/', '', $xml);
			$xml = preg_replace("/<bibliography[^\/]*\/>/", "", $xml);
			$xml = trim($xml);
			// get CRC
			$crc16 = $rusefi->calcCrcForTune($xml);
			if ($crc16 === FALSE) {
				error("Cannot calculate CRC for this tune!");
				return FALSE;
			}
			if (DEBUG) debug("findMSQ: crc16=".$crc16);

			DB::tryBind($st, ":crc", $crc16);
			$st->execute();
			$id = -1;
			// The 16-bit firmware CRC can collide and excludes auxiliary pages.
			// Verify the stored XML before rejecting a file as a duplicate.
			// addMSQ() stores XML with the same bibliography removal used above.
			while ($result = $st->fetch(PDO::FETCH_ASSOC)) {
				if ($result["xml"] === $xml) {
					$id = $result["id"];
					break;
				}
			}
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
			return FALSE;
		}
		
		return $id;
	}

	public function deleteFile($tune_id, $log_id) {
		if (!$this->connect()) return false;
		
		try
		{
			if ($tune_id > 0) {
				if (DEBUG) debug('deleteFile: Deleting Tune ' .$tune_id);
				$st = $this->db->prepare("DELETE FROM msqur_files,msqur_metadata USING msqur_files INNER JOIN msqur_metadata WHERE msqur_files.id = msqur_metadata.file AND msqur_metadata.id = :id");
				DB::tryBind($st, ":id", $tune_id);

				$st->execute();
				$ret = $st->rowCount();
				$st->closeCursor();

				$this->deleteUnusedEngines();
			}
			else if ($log_id > 0) {
				if (DEBUG) debug('deleteFile: Deleting LOG ' . $log_id);
				// first, get the file id
				$st = $this->db->prepare("SELECT msqur_files.id as file_id FROM msqur_files INNER JOIN msqur_logs ON msqur_logs.file_id = msqur_files.id WHERE msqur_logs.id = :id LIMIT 1");
				DB::tryBind($st, ":id", $log_id);
				$file_id = -1;
				$st->execute();
				$result = $st->fetch(PDO::FETCH_ASSOC);
				if ($result && count($result) > 0)
				{
					$st->closeCursor();
					$file_id = $result['file_id'];
					if (DEBUG) debug('deleteFile: LOG Found, file_id = ' . $file_id);
				}

				$st = $this->db->prepare("DELETE FROM msqur_files,msqur_logs USING msqur_files INNER JOIN msqur_logs WHERE msqur_files.id = msqur_logs.file_id AND msqur_logs.id = :id");
				DB::tryBind($st, ":id", $log_id);

				$st->execute();
				$ret = $st->rowCount();
				$st->closeCursor();

				if ($ret > 0 && $file_id > 0) {
					$res = @unlink($this->logFilesFolder.$file_id);
					if (DEBUG) debug('deleteFile: Deleting physical file: ' . ($res ? "SUCÑESS" : "FAIL"));
				}
			}

			return $ret > 0;
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
		
	}

	public function deleteUnusedEngines()
	{
		if (!$this->connect()) return false;
		
		try
		{
			$st = $this->db->prepare("DELETE FROM msqur_engines WHERE id NOT IN (SELECT engine FROM msqur_metadata)");
			$st->execute();
			$ret = $st->rowCount();	// return the number of deleted engines
			$st->closeCursor();

			return $ret;
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}

	public function addLog($file, $user_id, $tune_id, &$error)
	{
		global $rusefi;

		if (!$this->connect()) return -1;
		
		try
		{
			$flog = file_get_contents($file['tmp_name']);
			// todo: add compression?
			//$log = @zlib_decode($zlog);
			$log = $flog;
			if ($log === FALSE) {
				debug('DB::addLog(): Cannot get the log data!');
				return -1;
			}

			// this may take a while to complete...
			$info = $rusefi->getLogInfo($log, $dataPoints, $tunes);
			$data = pack("C*", ...$dataPoints);

			if ($tune_id < 0) {
				// try to find the corresponding tune from the log records
				// sort descending, from the longest tune to the shortest one
				usort($tunes, function ($a, $b) { return -(($a[1] - $a[0]) <=> ($b[1] - $b[0])); });
				foreach ($tunes as $t) {
					$res = $this->getTuneByCrc($t[2]);
					if ($res != null) {
						$tune_id = $res["tune_id"];
						break;
					}
				}

				if ($tune_id < 0) {
					$error = 'Please specify the tune corresponding to the log!';
					return -1;
				}
			}

			$st = $this->db->prepare("INSERT INTO msqur_files (type,data) VALUES (:type, ".$this->COMPRESS."(:log))");
			DB::tryBind($st, ":type", 1);	// 1=log
			DB::tryBind($st, ":log", "");

			if ($st->execute())
			{
				$id = $this->db->lastInsertId();

				// store the log as an external file
				if ($id > 0) {
					@file_put_contents($this->logFilesFolder.$id, $log);
				}

				$st->closeCursor();

				$st = $this->db->prepare("INSERT INTO msqur_logs (user_id,file_id,tune_id,info,data,uploadDate) VALUES (:user_id, :file_id, :tune_id, :info, :data, :uploaded)");
				DB::tryBind($st, ":user_id", $user_id);
				DB::tryBind($st, ":file_id", $id); //could do hash but for now, just the id
				DB::tryBind($st, ":tune_id", $tune_id);

				DB::tryBind($st, ":info", $info);
				DB::tryBind($st, ":data", $data);

				//TODO Make sure it's an int
				$dt = new DateTime();
				$dt = $dt->format('Y-m-d H:i:s');
				DB::tryBind($st, ":uploaded", $dt);

				if ($st->execute()) {
					$id = $this->db->lastInsertId();
				} else {
					error("Error inserting log metadata");
					if (DEBUG) {
						print_r($st->errorInfo());
					}
					$id = -1;
				}
				$st->closeCursor();
			} else {
				error("Error inserting log data");
				if (DEBUG) {
					print_r($st->errorInfo());
				}
				$id = -1;
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
			$id = -1;
		}

		if ($id > 0) {
			$this->deleteLogNotes($id);
			$this->addLogNotes($id, $tunes);
		}
		
		return $id;
	}

	public function browseLog($bq)
	{
		if (!$this->connect()) return null;
		
		try
		{
			$statement = "SELECT l.id as mid, l.user_id as user_id, l.info as info, l.data as data, l.uploadDate as uploadDate, l.views as views, l.tune_id as tune_id, name, make, code, numCylinders, displacement, compression, induction, firmware, signature FROM msqur_logs l INNER JOIN msqur_metadata m ON m.id = l.tune_id INNER JOIN msqur_engines e ON m.engine = e.id AND l.user_id = e.user_id WHERE ";
			$where = array();
			foreach ($bq as $col => $v)
			{
				//if ($v !== null) $statement .= "$col = :$col ";
				if ($v !== null) $where[] = "$col = :" . str_replace(".", "", $col) . " ";
			}
			
			if (count($where) === 0) $statement .= "1";
			else
			{
				$statement .= "(" . implode(" AND ", $where) . ")";
			}

			$statement .= " ORDER BY mid DESC";
			
			//echo $statement;
			debug($statement);
			
			$st = $this->db->prepare($statement);
			
			foreach ($bq as $col => $v)
			{
				if ($v !== null) $this->tryBind($st, ":" . str_replace(".", "", $col), $v);
			}
			
			if ($st->execute())
			{
				$result = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();
				return $result;
			}
			else echo '<div class="error">There was a problem constructing the browse query: </div>'; //var_export($st->errorInfo())
			
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return null;
	}

	public function getLog($id)
	{
		if (DEBUG) debug('Getting LOG for id: ' . $id);
		
		if (!$this->connect()) return null;
		
		$data = null;
		
		try
		{
			// [andreika]: use compressed data
			$st = $this->db->prepare("SELECT ".$this->UNCOMPRESS."(msqur_files.data) AS log, msqur_files.id as file_id FROM msqur_files INNER JOIN msqur_logs ON msqur_logs.file_id = msqur_files.id WHERE msqur_logs.id = :id LIMIT 1");
			DB::tryBind($st, ":id", $id);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				if (DEBUG) debug('LOG Found.');
				
				$st->closeCursor();
				$data = $result['log'];
				if (empty($data)) {
					$file_id = $result['file_id'];
					if (DEBUG) debug("Reading log from the file $file_id.");
					$data = @file_get_contents($this->logFilesFolder.$file_id);
				}
			} else {
				if (DEBUG) debug('LOG NOT Found.');
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return $data;
	}

	public function updateLogDataPoints($id)
	{
		global $rusefi;

		$log = $this->getLog($id);

		// this may take a while to complete...
		$info = $rusefi->getLogInfo($log, $dataPoints, $tunes);
		$data = pack("C*", ...$dataPoints);

		if (!$this->connect()) return false;
		
		try
		{
			if (DEBUG) debug('Updating log info and data points for ' . $id);
			$st = $this->db->prepare("UPDATE msqur_logs l SET l.data=:data, l.info=:info WHERE l.id = :id");
			DB::tryBind($st, ":id", $id);
			DB::tryBind($st, ":data", $data);
			DB::tryBind($st, ":info", $info);

			if ($st->execute())
			{
				if (DEBUG) debug('Log info & data updated!');
				$st->closeCursor();

				// update log-tune relationships
				$this->deleteLogNotes($id);
				$this->addLogNotes($id, $tunes);

				return true;
			}
			else
				if (DEBUG) debug('Unable to update log data.');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		return false;
	}

	public function updateTuneCrc($fileid)
	{
		global $rusefi;

		if (!$this->connect()) return -1;
		
		try
		{
			$st = $this->db->prepare("SELECT id,".$this->UNCOMPRESS."(data) AS xml FROM msqur_files WHERE type='0' AND " . ($fileid > 0 ? "id IN (SELECT file from msqur_metadata WHERE id=:id)" : "1"));
			if ($fileid > 0)
				DB::tryBind($st, ":id", $fileid);
			$st->execute();
			$result = $st->fetchAll(PDO::FETCH_ASSOC);
			if ($result && count($result) > 0)
			{
				$st->closeCursor();
				foreach ($result as $r)
				{
					$crc16 = $rusefi->calcCrcForTune($r["xml"]);
					if ($crc16 === FALSE) {
						error("Cannot calculate CRC for this tune!");
						echo "ERROR!\r\n";
						return -1;
					}

					echo "Tune id=".$r["id"]." crc16=".$crc16." ";
					$st = $this->db->prepare("UPDATE msqur_files SET crc=:crc WHERE id = :id");
					DB::tryBind($st, ":id", $r["id"]);
					DB::tryBind($st, ":crc", $crc16);
					if ($st->execute())
					{
						echo "CRC UPDATED!\r\n";
					}
					else {
						echo "CRC UPDATE FAILED!\r\n";
					}
					flush();
				
					$st->closeCursor();
				}
			}
		}
		catch (PDOException $e)
		{
			echo "DB Error!\r\n";
			$this->dbError($e);
		}
	}

	public function changeTuneNote($tune_id, $tuneNote)
	{
		global $rusefi;

		if (!$this->connect()) return false;
		
		try
		{
			if (DEBUG) debug('Updating tune note for '.$tune_id);
			$st = $this->db->prepare("UPDATE msqur_metadata SET tuneComment=:note WHERE id = :id");
			DB::tryBind($st, ":id", $tune_id);
			DB::tryBind($st, ":note", $tuneNote);

			if ($st->execute())
			{
				if (DEBUG) debug('Tune note updated!');
				$st->closeCursor();
				return true;
			}
			else
				if (DEBUG) debug('Unable to update tune note.');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		return false;
	}

	public function updateLogViews($id)
	{
		if (!$this->connect()) return false;
		
		try
		{
			$st = $this->db->prepare("UPDATE msqur_logs SET views = views + 1 WHERE id = :id");
			DB::tryBind($st, ":id", $id);
			$ret = $st->execute();
			$st->closeCursor();
			return $ret;
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}

	public function deleteLogNotes($id)
	{
		if (!$this->connect()) return false;
		
		try
		{
			$st = $this->db->prepare("DELETE FROM msqur_log_notes WHERE log_id = :id");
			DB::tryBind($st, ":id", $id);
			$st->execute();
			$ret = $st->rowCount();	// return the number of deleted engines
			$st->closeCursor();

			return $ret;
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}

	public function addLogNotes($id, $tunes)
	{
		if (!$this->connect()) return false;
		
		try
		{
			$ret = true;
			foreach ($tunes as $tune) {
				$st = $this->db->prepare("INSERT INTO msqur_log_notes (log_id,time_start,time_end,tune_crc,comment) VALUES (:log_id, :time_start, :time_end, :tune_crc, :comment)");
				DB::tryBind($st, ":log_id", $id);
				DB::tryBind($st, ":time_start", $tune[0]);
				DB::tryBind($st, ":time_end", $tune[1]);
				DB::tryBind($st, ":tune_crc", $tune[2]);
				DB::tryBind($st, ":comment", isset($tune[3]) ? $tune[3] : null);

				if (!$st->execute())
				{
					$ret = false;
				}
			}
			return $ret;
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
				
	}

	public function getLogNotes($log_id)
	{
		if (DEBUG) debug('Getting LOG notes for id: ' . $log_id);
		
		if (!$this->connect()) return array();
		
		try
		{
			$st = $this->db->prepare("SELECT time_start, time_end, tune_crc, comment FROM msqur_log_notes WHERE log_id = :log_id");
			DB::tryBind($st, ":log_id", $log_id);
			$st->execute();
			$result = $st->fetchAll(PDO::FETCH_ASSOC);
			$st->closeCursor();
			if ($result && count($result) > 0)
			{
				if (DEBUG) debug("Found " . count($result) . " records.");
				foreach ($result as &$r) {
					$tune_crc = $r["tune_crc"];
					$res = null;
					if (DEBUG) debug('* tune_crc=' . $tune_crc);
					// if tune_crc is set, that's the tune record, otherwise it's a user comment
					if ($tune_crc >= 0) {
						$res = $this->getTuneByCrc($tune_crc);
					}
					if (is_array($res)) {
						$r += $res;
					} else {
						$r += array("tune_id"=>-1, "tuneComment"=>null, "uploadDate"=>"?");
					}
					$st->closeCursor();
				}
				return $result;
			} else {
				if (DEBUG) debug('LOG NOT Found.');
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return array();
	}

	public function getTuneLogs($tuneId) 
	{
		if (DEBUG) debug('Getting LOGs for TUNE id: ' . $tuneId);
		
		if (!$this->connect()) return array();
		
		try
		{
			// first, get the CRC of the tune
			$st = $this->db->prepare("SELECT f.crc AS crc FROM msqur_files f INNER JOIN msqur_metadata m ON m.file = f.id WHERE m.id = :tune_id");
			DB::tryBind($st, ":tune_id", $tuneId);
			$st->execute();
			$result = $st->fetch(PDO::FETCH_ASSOC);
			$st->closeCursor();
			
			if ($result) {
				$crc = $result["crc"];
				if (DEBUG) debug('TUNE CRC=' . $crc);

				// now search using this CRC and also a user-defined tune ID
				$st = $this->db->prepare("SELECT l.id AS id, l.uploadDate AS uploadDate FROM msqur_log_notes n INNER JOIN msqur_logs l ON l.id = n.log_id WHERE n.tune_crc = :tune_crc OR l.tune_id = :tune_id GROUP BY l.id");
				DB::tryBind($st, ":tune_crc", $crc);
				DB::tryBind($st, ":tune_id", $tuneId);
				$st->execute();
				$result = $st->fetchAll(PDO::FETCH_ASSOC);
				$st->closeCursor();

				if ($result && count($result) > 0)
				{
					if (DEBUG) {
						debug("Found " . count($result) . " records:");
						debug("* ". print_r($result, TRUE));
					}
					return $result;
				}
			}
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		if (DEBUG) debug('LOGs NOT Found.');
		
		return array();
	}	

	public function hideTune($tune_id)
	{
		if (!$this->connect()) return false;
		
		try
		{
			if (DEBUG) debug('Hiding tune ID='.$tune_id);
			$st = $this->db->prepare("UPDATE msqur_metadata m SET m.hidden=1 WHERE m.id = :id");
			DB::tryBind($st, ":id", $tune_id);
			if ($st->execute())
			{
				if (DEBUG) debug('HIDDEN flag set!');
				$st->closeCursor();
				return true;
			}
			else
				if (DEBUG) debug('Unable to set HIDDEN flag!');
				
			$st->closeCursor();
		}
		catch (PDOException $e)
		{
			$this->dbError($e);
		}
		
		return false;
	}

	public function getTuneByCrc($tune_crc)
	{
		$st = $this->db->prepare("SELECT m.id as tune_id, tuneComment, uploadDate FROM msqur_files f INNER JOIN msqur_metadata m ON m.file = f.id WHERE crc = :crc LIMIT 1");
		DB::tryBind($st, ":crc", $tune_crc);
		$st->execute();
		$res = $st->fetch(PDO::FETCH_ASSOC);
		return $res;
	}
}

?>