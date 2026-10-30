<?php

require_once "common.php";
require_once "../src/view/view_ts_dialog.php";

class ViewTest extends PHPUnit\Framework\TestCase
{
    const INI = "testcases/CurrentTune.ini";
    const MSQ = "testcases/CurrentTune.msq";

    private function loadTune($stripPcVariables = false)
    {
        global $rusefi;
        $msq = new MSQ();
        $xml = preg_replace('/xmlns=".*?"/', '', file_get_contents(self::MSQ));
        if ($stripPcVariables) {
            // Older tunes do not save PcVariables at all.
            $xml = preg_replace('/<pcVariable\b.*?<\/pcVariable>/s', '', $xml);
        }
        $msq->msq = simplexml_load_string($xml);
        $msq->msqMap = INI::parse(self::INI, $msq->msq, array());
        $rusefi->msq = $msq;
        return $msq;
    }

    private function renderDialog($msq, $dialogId)
    {
        ob_start();
        try {
            printDialog('', $msq->msqMap, $msq, $dialogId, false);
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    private function tableCells($html, $selector)
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $values = array();
        foreach ($xpath->query($selector) as $cell) {
            $values[] = (float)$cell->textContent;
        }
        return $values;
    }

    public function testTablesWithMissingConstantsAreSkipped()
    {
        global $test;
        $test = new CommonTestHelper();
        $test->addFile("ini/rusefi/master/2026/10/27/uaefi/4200649275.ini", self::INI);

        // The INI defines tables whose data constants are flagged noMsqSave and are
        // therefore never saved in this tune, e.g. the long term fuel trim tables.
        $engine = array();
        $metadata = array();
        $html = (new MSQ())->parseMSQ(file_get_contents(self::MSQ), $engine, $metadata, "", array());

        $this->assertSame("rusEFI", $metadata['firmware']);
        $this->assertStringContainsString('<h3>VE Table</h3>', $html['tabList']);
        $this->assertStringContainsString('<h3>Ignition Table</h3>', $html['tabList']);
        // Its axes are PcVariables, which the tune saves as pcVariable elements.
        $this->assertStringContainsString('<h3>Solenoids Active By Gear</h3>', $html['tabList']);
        $this->assertStringNotContainsString('Long Term Fuel Trim Bank 1', $html['tabList']);
        $this->assertStringNotContainsString('class="error"', $html['tabList']);
    }

    public function testDefaultValuesAreParsedFromConstantsExtensions()
    {
        $msq = $this->loadTune();
        $defaults = $msq->msqMap["ConstantsExtensions"]["defaultValue"];
        $this->assertSame("-1 0 1 2 3 4 5 6 7 8", $defaults["gearCountArray"]);
        $this->assertSame("0", $defaults["veLoadSrc"]);
        $this->assertArrayNotHasKey("requiresPowerCycle", $msq->msqMap["ConstantsExtensions"]);
    }

    public function testPcVariableAxesComeFromTheTune()
    {
        $msq = $this->loadTune();
        $html = $this->renderDialog($msq, 'tcuSolenoidTableTbl');
        $this->assertStringNotContainsString('error', $html);
        $this->assertSame(array(-1.0, 0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0),
            $this->tableCells($html, '//table//thead/tr/th[position() > 1]'));
        $this->assertSame(array(1.0, 2.0, 3.0, 4.0, 5.0, 6.0),
            $this->tableCells($html, '//table//tr[td]/th'));
        $this->assertCount(60, $this->tableCells($html, '//table//tr/td'));

        // 2D curves with a PcVariable X axis.
        $html = $this->renderDialog($msq, 'boostOpenLoopGearAdderCurve');
        $this->assertCount(10, $this->tableCells($html, '//table//tbody/tr/th'));
        $html = $this->renderDialog($msq, 'rangeMatrixDialog');
        $this->assertCount(6, $this->tableCells($html, '//table//tbody/tr/th'));
    }

    public function testPcVariableAxesFallBackToIniDefaults()
    {
        $msq = $this->loadTune(true);
        $this->assertNull($msq->findConstant($msq->msq, 'gearCountArray', $digits, false));

        $html = $this->renderDialog($msq, 'tcuSolenoidTableTbl');
        $this->assertStringNotContainsString('error', $html);
        $this->assertSame(array(-1.0, 0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0),
            $this->tableCells($html, '//table//thead/tr/th[position() > 1]'));
        // The INI default lists 8 solenoids but the array is declared [6].
        $this->assertSame(array(1.0, 2.0, 3.0, 4.0, 5.0, 6.0),
            $this->tableCells($html, '//table//tr[td]/th'));
        $this->assertCount(60, $this->tableCells($html, '//table//tr/td'));

        $html = $this->renderDialog($msq, 'boostOpenLoopGearAdderCurve');
        $this->assertCount(10, $this->tableCells($html, '//table//tbody/tr/th'));
    }

    public function testNonSquareTableKeepsRowOrder()
    {
        $msq = $this->loadTune();
        // Mark the first row's last cell (10 columns x 6 rows) and check it stays in row 1.
        $table = $msq->msq->xpath('//constant[@name="tcuSolenoidTable"]')[0];
        $rows = preg_split('/\n+/', trim((string)$table), -1, PREG_SPLIT_NO_EMPTY);
        $rows[0] = preg_replace('/0\.0\s*$/', '1.0', $rows[0]);
        $table[0] = "\n" . implode("\n", $rows) . "\n";

        $html = $this->renderDialog($msq, 'tcuSolenoidTableTbl');
        $cells = $this->tableCells($html, '//table//tr[td]/td');
        $this->assertCount(60, $cells);
        $this->assertSame(1.0, $cells[9]);
        $this->assertSame(1.0, array_sum($cells));
    }

    public function testUnsavedConstantsAreReportedAsNotStored()
    {
        $msq = $this->loadTune();
        $html = $this->renderDialog($msq, 'ltftBank1Tbl');
        $this->assertStringContainsString('Long Term Fuel Trim Bank 1 is not stored in the tune file (ltft_table_bank1).', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('error', $html);

        // A constant that should have been saved is still reported as an error.
        $msq->msqMap["TableEditor"]["veTableTbl"]["zBinConstant"] = "notInThisTune";
        $html = $this->renderDialog($msq, 'veTableTbl');
        $this->assertStringContainsString("<div class='error'>Missing data for VE Table: notInThisTune</div>", $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
?>
