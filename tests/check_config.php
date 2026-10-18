<?php
// Standalone so configuration can be checked without a database or PHPUnit.

function runConfigCheck($directory, $assertions, $code)
{
    $bootstrap = <<<'PHP'
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require __DIR__ . '/config.php';
PHP;
    file_put_contents($directory . '/check.php', "<?php\n" . $bootstrap . "\n" . $code);
    $command = escapeshellarg(PHP_BINARY)
        . ' -d zend.assertions=' . $assertions
        . ' -d error_reporting=-1 -d log_errors=1 -d error_log= -d display_errors=0 '
        . escapeshellarg($directory . '/check.php');
    $process = proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('redirect', 1),
    ), $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP configuration check');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return array(proc_close($process), $output);
}

$directory = sys_get_temp_dir() . '/msqur-config-' . bin2hex(random_bytes(8));
mkdir($directory);
copy(__DIR__ . '/../src/config_rusefi.php.sample', $directory . '/config_rusefi.php');

try {
    foreach (array('config.php', 'config.php.dist') as $config) {
        copy(__DIR__ . '/../src/' . $config, $directory . '/config.php');
        foreach (array(-1, 0, 1) as $assertions) {
            $code = '$expected = ' . ($assertions < 0 ? '-1' : '(DEBUG ? 1 : 0)') . ';'
                . 'if ((int) ini_get("zend.assertions") !== $expected) { exit(1); }'
                . 'echo "ready";';
            list($status, $output) = runConfigCheck($directory, $assertions, $code);
            if ($status !== 0 || $output !== 'ready') {
                throw new RuntimeException("$config failed startup with zend.assertions=$assertions:\n$output");
            }
        }

        // Uncaught failures must retain the original error and a failing exit status.
        foreach (array('AssertionError', 'RuntimeException', 'Error') as $type) {
            list($status, $output) = runConfigCheck($directory, 1,
                'throw new ' . $type . '("config-check-failure");');
            if ($status === 0 || strpos($output, "Uncaught $type: config-check-failure") === false
                || strpos($output, 'Stack trace:') === false || strpos($output, 'ArgumentCountError') !== false) {
                throw new RuntimeException("$config did not report $type correctly:\n$output");
            }
        }

        // A real assertion must execute only when DEBUG enables it.
        list($status, $output) = runConfigCheck($directory, 1, <<<'PHP'
$evaluated = false;
try {
    assert(($evaluated = true) && false, 'config-check-assertion');
} catch (AssertionError $error) {
    echo 'asserted';
}
if ($evaluated !== DEBUG) { exit(1); }
if (!DEBUG) { echo 'skipped'; }
PHP
        );
        $expected = $config === 'config.php' ? 'asserted' : 'skipped';
        if ($status !== 0 || $output !== $expected) {
            throw new RuntimeException("$config did not honor DEBUG for assertions:\n$output");
        }
        echo "$config: startup and error reporting passed\n";
    }

    // The sample has no DB host, so the application can also bootstrap offline.
    copy(__DIR__ . '/../src/config.php', $directory . '/config.php');
    $source = var_export(realpath(__DIR__ . '/../src'), true);
    list($status, $output) = runConfigCheck($directory, 1,
        'set_include_path(__DIR__ . PATH_SEPARATOR . ' . $source . ');'
        . 'require "msqur.php"; echo "ready";');
    if ($status !== 0 || $output !== 'ready') {
        throw new RuntimeException("Application failed to bootstrap:\n$output");
    }
    echo "Application bootstrap passed\n";
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
