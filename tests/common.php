<?php

global $msqur, $rusefi;
require_once "../src/msqur.php";

class CommonTestHelper
{
	public $db;

	private $files = array();

	private function importDbTables($pdo, $fileName)
	{
		$query = '';
		$sqlScript = file($fileName);
		foreach ($sqlScript as $line) {
			$startWith = substr(trim($line), 0 ,2);
			$endWith = substr(trim($line), -1 ,1);
	
			if (empty($line) || $startWith == '--' || $startWith == '/*' || $startWith == '//') {
				continue;
			}
		
			$query = $query . $line;
			if ($endWith == ';') {
				// convert to SQLite: remove all extended MySql attributes
				$query = preg_replace("/((CHARACTER\s+SET|COLLATE|ENGINE|DEFAULT CHARSET)[\s=]+[A-Za-z0-9_]+|UNSIGNED|,\s+PRIMARY\s+KEY\s+\([^)]+\))/", "", $query);
				// this fixes primary keys
				$query = preg_replace("/int\(?[0-9]*\)? NOT NULL AUTO_INCREMENT/", "INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL", $query);
				if ($pdo->exec($query) === FALSE) {
					die('Problem: '.print_r($pdo->errorInfo(), TRUE).' in executing the SQL query: ' . $query);
				}
				$query= '';		
			}
		}
	}

	public function createPdo() {
		$pdo = new \PDO('sqlite::memory:', null, null);
		$this->importDbTables($pdo, "../db/msqur-rusefi.sql");
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING/*ERRMODE_EXCEPTION*/);
		return $pdo;
	}

	public function getTableFields($tbl, $field) {
		global $msqur;
		$st = $msqur->db->db->query("SELECT $field FROM $tbl WHERE 1;");
        return $st->fetchAll(PDO::FETCH_ASSOC);
	}

	public function startTest() {
		global $msqur;
		$msqur->db->db = $this->createPdo();
		// sqlite doesn't use compression
		$msqur->db->COMPRESS = "";
		$msqur->db->UNCOMPRESS = "";
	}

	public function addFile($fName, $realName) {
		$this->files[$fName] = empty($realName) ? null : file_get_contents($realName);
	}

	public function file($f) {
		if (!isset($this->files[$f]))
			return file($f);
		return explode("\n", $this->files[$f]);
	}

	public function file_get_contents($f) {
		if (!isset($this->files[$f]))
			return file_get_contents($f);
		return $this->files[$f];
	}

	public function file_put_contents($f, $c) {
		if (!isset($this->files[$f]))
			return file_put_contents($f, $c);
		$this->files[$f] = $c;
		return strlen($c);
	}
	
	public function debugLog($t, $m) {
		// todo: debug mode?
		//echo $m."\r\n";
	}
}

?>
