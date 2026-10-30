<?php

require_once "common.php";

class ViewTest extends PHPUnit\Framework\TestCase
{
    public function testTablesWithMissingConstantsAreSkipped()
    {
        global $test;
        $test = new CommonTestHelper();
        $test->addFile("ini/rusefi/master/2026/10/27/uaefi/4200649275.ini", "testcases/CurrentTune.ini");

        // The INI defines tables whose axis or data constants are not saved in this tune,
        // for example the TCU solenoid table and the long term fuel trim tables.
        $engine = array();
        $metadata = array();
        $html = (new MSQ())->parseMSQ(file_get_contents("testcases/CurrentTune.msq"), $engine, $metadata, "", array());

        $this->assertSame("rusEFI", $metadata['firmware']);
        $this->assertStringContainsString('<h3>VE Table</h3>', $html['tabList']);
        $this->assertStringContainsString('<h3>Ignition Table</h3>', $html['tabList']);
        $this->assertStringNotContainsString('Solenoids Active By Gear', $html['tabList']);
        $this->assertStringNotContainsString('Long Term Fuel Trim Bank 1', $html['tabList']);
        $this->assertStringNotContainsString('class="error"', $html['tabList']);
    }
}
?>
