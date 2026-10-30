<?php
// Run directly with PHP; render local tune fixtures without a database.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('DEBUG', false);
define('DB_HOST', '');
define('RUSEFI_ADMIN_USER_IDS', array());

require __DIR__ . '/../src/ini.php';
require __DIR__ . '/../src/msq.php';
require __DIR__ . '/../src/db.php';
$msqur = (object) array('db' => new DB());
require __DIR__ . '/../src/rusefi.php';
require __DIR__ . '/../src/view/view_ts_dialog.php';

function checkViewValue($actual, $expected, $description)
{
    if ($actual !== $expected) {
        throw new RuntimeException($description . ': got ' . var_export($actual, true));
    }
}

$msq = new MSQ();
$msq->msqMap = array();
$msq->msq = simplexml_load_string(<<<'XML'
<msq>
<constant name="bins" rows="2" cols="1" digits="1"> 1,000.5   2.5 </constant>
<constant name="single" rows="1" cols="1" digits="0">42</constant>
<constant name="label" digits="0">"Tune label"</constant>
</msq>
XML
);
checkViewValue($rusefi->getMsqConstantFull('bins', $msq, $digits), array(1000.5, 2.5),
    'Array shape and whitespace without an INI definition');
checkViewValue($digits, 1, 'Array precision from MSQ');
checkViewValue($rusefi->getMsqConstantFull('single', $msq, $digits), array(42.0),
    'Single-element array keeps its shape');
checkViewValue($rusefi->getMsqConstantFull('label', $msq, $digits), 'Tune label',
    'Scalar without an INI definition stays scalar');
checkViewValue($rusefi->getMsqConstantFull('missing', $msq, $digits), null, 'Missing constant');

foreach (array('CurrentTune', 'mazda_B6ZE') as $fixture) {
    $msq = new MSQ();
    $xml = file_get_contents(__DIR__ . '/testcases/' . $fixture . '.msq');
    $msq->msq = simplexml_load_string(preg_replace('/xmlns=".*?"/', '', $xml));
    $msq->msqMap = INI::parse(__DIR__ . '/testcases/' . $fixture . '.ini', $msq->msq, array());
    $rusefi->msq = $msq;

    // Render the complete Fuel -> VE dialog, including its nested table panel.
    ob_start();
    try {
        printDialog('', $msq->msqMap, $msq, 'veTableDialog', false);
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $document = new DOMDocument();
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $tables = $xpath->query('//*[@id="tab_veTableTbl"]//table');
    checkViewValue($tables->length, 1, $fixture . ' VE table rendered');
    $table = $tables->item(0);

    // Compare every rendered axis bin and VE cell directly with the saved tune.
    foreach (array(
        'veRpmBins' => './/thead/tr/th[position() > 1]',
        'veLoadBins' => './/tr[td]/th',
        'veTable' => './/tr/td',
    ) as $constant => $selector) {
        $saved = $msq->msq->xpath('//constant[@name="' . $constant . '"]')[0];
        $expected = array_map('floatval', preg_split('/\s+/', trim((string)$saved)));
        $actual = array();
        foreach ($xpath->query($selector, $table) as $cell) {
            $actual[] = (float)$cell->textContent;
        }
        checkViewValue(count($actual), count($expected), $fixture . ' ' . $constant . ' cell count');
        foreach ($expected as $index => $value) {
            // PHP's float-to-string conversion can trim insignificant digits.
            checkViewValue(abs($actual[$index] - $value) < 1e-9, true,
                $fixture . ' ' . $constant . ' value at ' . $index);
        }
    }
}

echo "Tune VE dialog rendering checks passed\n";
