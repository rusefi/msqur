<?php

require_once "common.php";

class UploadTest extends PHPUnit\Framework\TestCase
{
    public function testTuneUpload()
    {
		global $msqur, $rusefi, $test, $embResult;
		$test = new CommonTestHelper();
		$test->startTest();

		// add tune file
		$uploadedFile = "testcases/mazda_B6ZE.msq";
		$_FILES = [
            'upload-file' => [
                'name' => $uploadedFile,
                'size' => filesize($uploadedFile),
                'tmp_name' => $uploadedFile,
                'error' => 0
            ]
        ];

        // add ini file
        $test->addFile("ini/rusefi/2020/07/06/mre_f4/2108843221.ini", "testcases/mazda_B6ZE.ini");

        // simulate login
        $_POST = array("rusefi_token"=>"1");
        $rusefi->userid = 1;

		// call the main code
		ob_start();
		try {
			include("../src/upload.php");
		} finally {
			$output = ob_get_clean();
		}
		$response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		$this->assertArrayNotHasKey("error", $response);
		$this->assertArrayHasKey("info", $response);

		// check if tables are filled with the data
		$this->assertCount(1, $test->getTableFields("msqur_metadata", "id"));
		$this->assertCount(1, $test->getTableFields("msqur_files", "id"));
		$this->assertCount(1, $test->getTableFields("msqur_engines", "id"));
		$metadata = $msqur->db->db->query("SELECT fileFormat, signature, firmware FROM msqur_metadata")->fetch(PDO::FETCH_ASSOC);
		$this->assertSame(array("fileFormat" => "5.0", "signature" => "2020.07.06.mre_f4.2108843221", "firmware" => "rusEFI"), $metadata);
		$this->assertNotEmpty($msqur->db->db->query("SELECT html FROM msqur_files")->fetchColumn());

		// get the tune info
		$tune = $msqur->browse(array(), 0, "msq");
		$this->assertCount(1, $tune);
		$tune_id = $tune[0]["mid"];
		$this->assertEquals(1, $tune_id);
		// todo: check all tune fields

		// get the engine info from the tune
		$engine = $rusefi->getEngineFromTune($tune_id);
		// the engine has 7 fields
		$this->assertCount(7, $engine);
		// todo: check the engine fields

		//$html = $msqur->view($tune_id, "ts", array());

		// todo: check the uploaded tune contents
    }
}
?>
