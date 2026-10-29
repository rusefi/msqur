<?php
// Run directly with PHP; fixtures are local and the database is in memory.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('DEBUG', false);
define('DB_HOST', '');
define('RUSEFI_ADMIN_USER_IDS', array());

function debug($message) {}
function error($message)
{
    throw new RuntimeException($message);
}

require __DIR__ . '/../src/ini.php';
require __DIR__ . '/../src/msq.php';
require __DIR__ . '/../src/db.php';
$msqur = (object) array('db' => new DB());
require __DIR__ . '/../src/rusefi.php';

function checkTuneValue($actual, $expected, $description)
{
    if ($actual !== $expected) {
        throw new RuntimeException($description . ': got ' . var_export($actual, true)
            . ', expected ' . var_export($expected, true));
    }
}

// Exercise production signature resolution without downloading INIs in CI.
// CurrentTune.ini was saved from:
// https://rusefi.com/online/ini/rusefi/master/2026/10/27/uaefi/4200649275.ini
$test = new class {
    public function file($path)
    {
        $fixtures = array(
            'ini/rusefi/2020/07/06/mre_f4/2108843221.ini' => 'mazda_B6ZE.ini',
            'ini/rusefi/master/2026/10/27/uaefi/4200649275.ini' => 'CurrentTune.ini',
        );
        return file(isset($fixtures[$path]) ? __DIR__ . '/testcases/' . $fixtures[$path] : $path);
    }
};

function parseCrcFixture($ini, $xml)
{
    $file = tempnam(sys_get_temp_dir(), 'msqur-crc-');
    try {
        file_put_contents($file, $ini);
        $msq = new MSQ();
        $msq->msq = simplexml_load_string($xml);
        $msq->msqMap = INI::parse($file, $msq->msq, array());
        return $msq;
    } finally {
        unlink($file);
    }
}

$firstFields = <<<'INI'
mode = bits, U08, 0, [0:0], "Off", "On"
flag = bits, U08, 0, [1:1], "No", "Yes"
value = scalar, U16, 2, "", {mode ? 0.5 : 1.0}, 0, 0, 100, 1

INI;
$lastFields = <<<'INI'
samples = array, S16, 4, [2], "", {mode ? 0.1 : 1.0}, 0, -100, 100, 1
warning_message = string, ASCII, 8, 2
rate = scalar, U16, 10, "", 1.0E-5, 0, 0, 1, 5

INI;
$xml = <<<'XML'
<msq><page number="0">
<constant name="mode">"On"</constant>
<constant name="flag">"Yes"</constant>
<constant name="value">6</constant>
<constant name="samples">-1.2 2.3</constant>
<constant name="warning_message">XY</constant>
<constant name="rate">3.0E-4</constant>
</page><page number="1">
<constant name="other">This overlaps page 1 and extends past its end</constant>
<constant name="otherTail">255</constant>
</page></msq>
XML;
$singleIni = "[Constants]\npageSize = 12\npage = 1\n" . $firstFields . $lastFields;
$multiIni = "[Constants]\npageSize = 12, 32\npage = 2\nother = string, ASCII, 0, 32\npage = 1\n"
    . $firstFields . "page = 2\notherTail = scalar, U08, 31, \"\", 1, 0, 0, 255, 0\npage = 1\n"
    . $lastFields . "page = 2\n";
// Hand-packed page 1: two bits, one unused byte, scaled U16, two signed
// samples, zeroed warning bytes, and the scientific-notation scalar.
$expectedData = hex2bin('03000c00f4ff170000001e00');
$expectedCrc = crc32($expectedData) & 0xffff;
$single = parseCrcFixture($singleIni, $xml);
$multi = parseCrcFixture($multiIni, $xml);
checkTuneValue($rusefi->calcCrc($single), $expectedCrc, 'Single-page CRC');
checkTuneValue($rusefi->calcCrc($multi), $expectedCrc, 'Multi-page CRC with page switches');
checkTuneValue($multi->msqMap['constantPages']['mode'], 1, 'First page-1 field');
checkTuneValue($multi->msqMap['constantPages']['rate'], 1, 'Repeated page-1 declaration');
checkTuneValue($multi->msqMap['constantPages']['other'], 2, 'Auxiliary page field');
checkTuneValue($rusefi->msq, null, 'Scale evaluation does not change the active tune');

$multi->msq->xpath('//constant[@name="other"]')[0][0] = 'changed';
checkTuneValue($rusefi->calcCrc($multi), $expectedCrc, 'Auxiliary pages do not affect the CRC');
$expectedData[8] = 'X';
$expectedData[9] = 'Y';
checkTuneValue($rusefi->calcCrc($multi, array()), crc32($expectedData) & 0xffff, 'Explicit skip fields');

$multi->msq->xpath('//constant[@name="value"]')[0][0] = '8';
checkTuneValue($rusefi->calcCrc($multi), crc32(hex2bin('03001000f4ff170000001e00')) & 0xffff,
    'Page-1 changes affect the CRC');

$legacyXml = file_get_contents(__DIR__ . '/testcases/mazda_B6ZE.msq');
checkTuneValue($rusefi->calcCrcForTune($legacyXml), 32439, 'Existing single-page tune CRC');

$currentXml = file_get_contents(__DIR__ . '/testcases/CurrentTune.msq');
$currentCrc = $rusefi->calcCrcForTune($currentXml);
checkTuneValue(is_int($currentCrc), true, 'CurrentTune CRC is an integer');
checkTuneValue($currentCrc >= 0 && $currentCrc <= 0xffff, true, 'CurrentTune CRC fits 16 bits');

// Exercise the upload preflight, storage, and duplicate check with the real tune.
$db = $msqur->db;
$db->db = new PDO('sqlite::memory:');
$db->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->COMPRESS = '';
$db->UNCOMPRESS = '';
$db->db->exec('CREATE TABLE msqur_files (id INTEGER PRIMARY KEY AUTOINCREMENT, type INTEGER, crc INTEGER, data BLOB)');
$db->db->exec('CREATE TABLE msqur_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT, file INTEGER,
    engine INTEGER, fileFormat TEXT, signature TEXT, author TEXT, uploadDate TEXT, writeDate TEXT, tuneComment TEXT)');
$file = array('name' => 'CurrentTune.msq', 'tmp_name' => __DIR__ . '/testcases/CurrentTune.msq');
$message = '';
checkTuneValue($rusefi->checkIfTuneCanBeUploaded(array($file), null, $message), true, 'New tune upload preflight');
// A CRC match is only a candidate: unrelated tunes can share all 16 bits.
$collision = $db->db->prepare('INSERT INTO msqur_files (id, type, crc, data) VALUES (984, 0, :crc, :xml)');
$collision->execute(array(':crc' => $currentCrc, ':xml' => $legacyXml));
$db->db->exec('INSERT INTO msqur_metadata (id, file) VALUES (1001, 984)');
checkTuneValue($rusefi->checkIfTuneCanBeUploaded(array($file), null, $message), true, 'CRC collision is not a duplicate');

// A failed earlier upload may leave a file row without a corresponding tune.
$normalizedXml = trim(preg_replace('/<bibliography[^\/]*\/>/', '', mb_convert_encoding($currentXml, 'UTF-8')));
$orphan = $db->db->prepare('INSERT INTO msqur_files (type, crc, data) VALUES (0, :crc, :xml)');
$orphan->execute(array(':crc' => $currentCrc, ':xml' => $normalizedXml));
checkTuneValue($rusefi->checkIfTuneCanBeUploaded(array($file), null, $message), true, 'Orphan file does not block an upload');

$id = $db->addMSQ($file, null);
checkTuneValue((int)$id > 0, true, 'Tune stored successfully');
$stored = $db->db->prepare('SELECT f.crc FROM msqur_files f INNER JOIN msqur_metadata m ON m.file = f.id WHERE m.id = :id');
$stored->execute(array(':id' => $id));
checkTuneValue((int)$stored->fetchColumn(), $currentCrc, 'Stored CRC');
checkTuneValue((int)$db->findMSQ($file, null), (int)$id, 'Duplicate lookup returns the public tune ID');
checkTuneValue($rusefi->checkIfTuneCanBeUploaded(array($file), null, $message), false, 'Duplicate tune rejected');
checkTuneValue(strpos($message, 'already exists') !== false, true, 'Duplicate rejection reason');
checkTuneValue(strpos($message, 'href="view.php?msq=' . $id . '"') !== false, true, 'Link to existing tune');

$temporaryTune = tempnam(sys_get_temp_dir(), 'msqur-duplicate-');
try {
    $temporaryFile = array('name' => 'CurrentTune.msq', 'tmp_name' => $temporaryTune);
    $resavedXml = preg_replace('/writeDate="[^"]*"/', 'writeDate="Fri Oct 30 18:12:15 CET 2026"', $currentXml);
    file_put_contents($temporaryTune, $resavedXml);
    checkTuneValue($rusefi->checkIfTuneCanBeUploaded(array($temporaryFile), null, $message), false,
        'Changing only the bibliography remains a duplicate');

    $luaXml = str_replace('<constant name="luaScript"/>', '<constant name="luaScript">print("changed")</constant>', $currentXml, $changed);
    checkTuneValue($changed, 1, 'Lua fixture changed');
    checkTuneValue($rusefi->calcCrcForTune($luaXml), $currentCrc, 'Auxiliary-page change shares the firmware CRC');
    file_put_contents($temporaryTune, $luaXml);
    checkTuneValue($rusefi->checkIfTuneCanBeUploaded(array($temporaryFile), null, $message), true,
        'Different auxiliary-page contents can be uploaded');
} finally {
    unlink($temporaryTune);
}
echo "Tune CRC and upload regression checks passed\n";
