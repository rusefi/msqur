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

        // Another user already has this exact file and the same vehicle name.
        $existingEngine = $msqur->addOrUpdateVehicle(202, "Miata", "Mazda", "B6ZE", 1.6, 9.1, 0);
        $existingTune = $msqur->db->addMSQ($_FILES['upload-file'], $existingEngine);
        $this->assertGreaterThan(0, $existingTune);

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
		$this->assertCount(2, $test->getTableFields("msqur_metadata", "id"));
		$this->assertCount(2, $test->getTableFields("msqur_files", "id"));
		$this->assertCount(2, $test->getTableFields("msqur_engines", "id"));

		// get the tune info
		$tune = $msqur->browse(array(), 0, "msq");
		$this->assertCount(2, $tune);
		$tune_id = $tune[0]["mid"];
		$this->assertEquals(2, $tune_id);
		$this->assertStringContainsString('href="view.php?msq=' . $tune_id . '"', implode('', $response['info']));
		$metadata = $msqur->db->db->query("SELECT fileFormat, signature, firmware FROM msqur_metadata WHERE id = " . (int)$tune_id)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame(array("fileFormat" => "5.0", "signature" => "2020.07.06.mre_f4.2108843221", "firmware" => "rusEFI"), $metadata);
		$this->assertNotEmpty($msqur->db->db->query("SELECT html FROM msqur_files WHERE id = (SELECT file FROM msqur_metadata WHERE id = " . (int)$tune_id . ")")->fetchColumn());
		$fileIds = $msqur->db->db->query("SELECT file FROM msqur_metadata ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertNotEquals($fileIds[0], $fileIds[1]);
		// todo: check all tune fields

		// get the engine info from the tune
		$engine = $rusefi->getEngineFromTune($tune_id);
		// the engine has 7 fields
		$this->assertCount(7, $engine);
		$this->assertEquals(1, $engine['user_id']);
		$this->assertEquals(202, $rusefi->getEngineFromTune($existingTune)['user_id']);
		// todo: check the engine fields

		//$html = $msqur->view($tune_id, "ts", array());

		// todo: check the uploaded tune contents

        // Each uploader can attach logs only to their own tune record.
        $this->assertTrue($rusefi->checkIfTuneIsValidForUser(1, $tune_id));
        $this->assertFalse($rusefi->checkIfTuneIsValidForUser(1, $existingTune));
        $this->assertTrue($rusefi->checkIfTuneIsValidForUser(202, $existingTune));
        $this->assertFalse($rusefi->checkIfTuneIsValidForUser(202, $tune_id));

        // Re-uploading links each user to their own existing tune.
        foreach (array(202 => $existingTune, 1 => $tune_id) as $userId => $expectedTune) {
            $rusefi->userid = $userId;
            $message = '';
            $this->assertFalse($rusefi->checkIfTuneCanBeUploaded(array($_FILES['upload-file']), $message));
            $this->assertStringContainsString('href="view.php?msq=' . $expectedTune . '"', $message);
        }
        $this->assertCount(2, $test->getTableFields("msqur_metadata", "id"));
        $this->assertCount(2, $test->getTableFields("msqur_files", "id"));
    }
}
?>
