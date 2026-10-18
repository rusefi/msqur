<?php
// Run directly with PHP; no database or PHPUnit is needed.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('DEBUG', false);
require __DIR__ . '/../src/util.php';
require __DIR__ . '/../src/ini.php';

function checkIniValue($actual, $expected, $description)
{
    if ($actual !== $expected) {
        throw new RuntimeException($description . ': got ' . var_export($actual, true));
    }
}

$previous = new Exception('original failure');
$exception = new MSQ_ParseException('parse failed', '<p>parse failed</p>', 42, $previous);
checkIniValue($exception->getPrevious(), $previous, 'Previous exception');
checkIniValue($exception->getHTMLMessage(), '<p>parse failed</p>', 'HTML error message');
checkIniValue((new MSQ_ParseException('parse failed'))->getPrevious(), null, 'Optional previous exception');

$file = tempnam(sys_get_temp_dir(), 'msqur-ini-');
try {
    file_put_contents($file, <<<'INI'
#define LABEL=Fuel
globalLabel = "Global $LABEL"
globalExpression = { base + 1 }
[Constants]
pageSize = 16
label = "Tune $LABEL"
scale = { 1 / 100 }
dependent = { base + 1 }
[Menu]
menu = "Fuel Settings"
subMenu = fuelDialog, "Fuel Dialog"
[OutputChannels]
runtimeValue = { base + 1 }, "units"
[PcVariables]
runtimeDependent = { runtimeValue + 2 }
scalarValue = 42
[UserDefined]
dialog = fuelDialog, "Dialog $LABEL"
field = "Base", base
INI
    );
    $msq = simplexml_load_string('<msq><constant name="base">5</constant></msq>');
    $parsed = INI::parse($file, $msq, array());

    checkIniValue($parsed['globalLabel'], 'Global Fuel', 'Global macro');
    checkIniValue($parsed['globalExpression'], '{ base + 1 }', 'Global tune expression');
    checkIniValue($parsed['Constants']['pageSize'], '16', 'Scalar constant');
    checkIniValue($parsed['Constants']['label'], 'Tune Fuel', 'Section macro');
    checkIniValue($parsed['Constants']['scale'], '0.01', 'Constant expression');
    checkIniValue($parsed['Constants']['dependent'], '{ base + 1 }', 'Tune expression');
    checkIniValue($parsed['menu']['Fuel Settings']['menu'][0], 'Fuel Settings', 'Menu title');
    checkIniValue($parsed['menu']['Fuel Settings']['subMenu'][0], array('fuelDialog', 'Fuel Dialog'), 'Submenu');
    checkIniValue($parsed['outputs']['runtimeValue'], "return \$rusefi->getMsqConstant('base') + 1;", 'Output expression');
    checkIniValue($parsed['PcVariables']['runtimeDependent'], '{ runtimeValue + 2 }', 'Output reference');
    checkIniValue($parsed['PcVariables']['scalarValue'], '42', 'Scalar with known output channels');
    checkIniValue($parsed['dialog']['fuelDialog']['dialog'], array('fuelDialog', 'Dialog Fuel', 'key' => 'dialog'), 'Dialog macro');
    echo "INI parsing and exception checks passed\n";
} finally {
    unlink($file);
}
