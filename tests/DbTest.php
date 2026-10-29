<?php

require_once "common.php";

class DbTest extends PHPUnit\Framework\TestCase
{
    public function testTables()
    {
		$test = new CommonTestHelper();
		$pdo = $test->createPdo();

		//$st = $pdo->exec("SHOW TABLES");
		$st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");

        $tables = $st->fetchAll(PDO::FETCH_ASSOC);
        // filter out internal sqlite tables, leave only our tables
		$tables = array_filter($tables, function ($val) {
			return preg_match("/msqur_[a-z]+/", $val["name"]);
		});
	
		// we have 5 tables so far
        $this->assertCount(5, $tables);
    }
}
?>
