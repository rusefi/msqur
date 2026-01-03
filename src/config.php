<?php
define('CONFIG_VERSION', "7");
define('LOG_FILE', "../msqur.log"); //Ew

require "config_rusefi.php";

define('LOCAL', TRUE);
define('DEBUG', TRUE/*FALSE*/);	// [andreika]: todo: temporary enabled!
define('DISABLE_MSQ_CACHE', FALSE);

error_reporting(E_ALL);

ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');

//Default in case it's not set in php.ini
//MSQUR-1
//date_default_timezone_set('UTC');

ini_set('zend.assertions', DEBUG ? 1 : 0);

$error_messages = array();

//Could move these to msqur class, but php
function debuglog($type, $message)
{
	global $test;
	if (isset($test)) {
		$test->debugLog($type, $message);
	}
	else if (!error_log("$type: $message\n", 3, LOG_FILE))
		error_log("Error writing to logfile: " . LOG_FILE);
}

function debug($message)
{
	debuglog("DEBUG", $message);
}

function warn($message)
{
	debuglog("WARN", $message);
}

function error($message)
{
	global $error_messages;
	debuglog("ERROR", $message);
	$error_messages[] = $message;
}

function get_all_error_messages()
{
	global $error_messages;
	$msg = "<ul>\n";
	$list = array_reverse($error_messages);
	foreach ($list as $err) {
		$msg .= "<li>" . $err. "</li>\n";
	}
	$msg .= "</ul>\n";
	return $msg;
}

function pageError($err) {
	global $rusefi, $viewMode;
	http_response_code(404);
	include "view/header.php";
	echo '<div class="error">'.$err.'</div>';
	include "view/footer.php";
	die;
}

//Setup assert() callback
function msqur_assert_handler($file, $line, $code)
{
    error("Assertion Failed: '$code'\nFile '$file', line '$line'");
}
set_exception_handler('msqur_assert_handler');

if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach($arr as $key => $unused) {
            return $key;
        }
        return NULL;
    }
}

if (isset($_SERVER['QUERY_STRING']))
	parse_str($_SERVER['QUERY_STRING'], $_GET);
?>
