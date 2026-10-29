<?php

require_once "common.php";

class MlgTest extends PHPUnit\Framework\TestCase
{
    public function testMlgParser() {
		$data = file_get_contents("testcases/2020-06-30_19.21.53_mck1117_large.mlg");
		$mlgParser = new MlgParser();
		$mlgParser->initStats(false, false);
		// parse
		$ret = $mlgParser->parseBinary($data);
        $this->assertEquals("warn", $ret["status"]);

		$stats = $mlgParser->getStats(strlen($data));
		$this->assertEquals("34 min 8 secs", $stats["duration"]);
		// todo: check other log stats
	}

    public function testLogTuneCrc() {
		$data = file_get_contents("testcases/log_2tunes_crc.mlg");
		$mlgParser = new MlgParser();
		$mlgParser->initStats(false, true);
		// parse
		$ret = $mlgParser->parseBinary($data);
        $this->assertEquals("ok", $ret["status"]);

		$stats = $mlgParser->getStats(strlen($data));
		// check if the log is processed correctly
		$this->assertEquals("22.7 secs", $stats["duration"]);

		// check the tunes
		$tunes = $mlgParser->getTunes();
		$this->assertEquals(2, count($tunes));
		$this->assertEquals(0x27B9, $tunes[0][2]);
		$this->assertEquals(0x34D5, $tunes[1][2]);
		// todo: check times
	}
}
?>
