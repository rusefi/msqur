<?php
/* msqur - MegaSquirt .msq file viewer web application
Copyright 2014-2019 Nicholas Earwood nearwood@gmail.com https://nearwood.dev

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>. */

require_once "msqur.php";

global $isEmbedded;
$isEmbedded = (isset($_POST['rusefi_token']) && isset($_FILES) && isset($_FILES['upload-file']));

/**
 * @brief Restructure file upload array
 *
 * Extended description goes here.
 * @param $file_post array
 */ 
function fixFileArray(&$file_post)
{//From php.net anonymous comment
	$file_ary = array();
	$file_count = is_array($file_post['name']) ? count($file_post['name']) : 1;
	$file_keys = array_keys($file_post);
	
	for ($i = 0; $i < $file_count; $i++)
	{
		foreach ($file_keys as $key)
		{
			$file_ary[$i][$key] = is_array($file_post[$key]) ? $file_post[$key][$i] : $file_post[$key];
		}
	}

	return $file_ary;
}

/**
 * @brief Sanity check for uploaded files.
 * @param $files array
 * @returns $files array with bad apples removed.
 */
function checkUploads($files)
{//Expects fixed array instead of $_FILES array
	foreach ($files as $index => $file)
	{
		//Discard any with errors
		if ($file['error'] != UPLOAD_ERR_OK)
		{
			unset($files[$index]);
			continue;
		}
		
		//Check sizes against 64MiB
		if ($file['size'] > 64*1048576)
		{
			unset($files[$index]);
			continue;
		}
		
		//Get and check mime types (ignoring provided ones)
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->file($file['tmp_name']);
		if (!acceptableMimeType($mimeType))
		{
			if (DEBUG) warn('File: ' . $file['tmp_name'] . ': Invalid MIME type ' . $mimeType);
			unset($files[$index]);
			continue;
		}
	}

	return $files;
}

/**
 * @brief Check that a mime type matches ones we think are OK.
 * @param $mimeType {string} MIME type
 * @returns true or false
 */
function acceptableMimeType($mimeType) {
	switch ($mimeType) {
		case "application/xml":
		case "application/octet-stream":
		case "text/xml":
		case "text/plain":
			return true;
		default:
			return FALSE;
	}
}

function addOutput($type, $msg)
{
	global $isEmbedded, $embResult;
	if (!$isEmbedded)
	{
		echo "<script>var newURL = 'index.php'; window.history.replaceState(null, null, newURL);</script>";
		echo '<div class="' . $type . '">' . $msg . '</div>';
	} else 
	{
		if (!isset($embResult[$type]))
			$embResult[$type] = array();
		$embResult[$type][] = $msg . "\r\n";
	}
}

function fillEngineVarsFromXml($xml, &$vars)
{
	$eInfo = array(// "msqur_field_name": ["TS_tune_file_constant_name"]
			"name" => "vehicleName",
			"make" => "engineMake",
			"code" => "engineCode",
			"displacement" => "displacement",
			"compression" => "compressionRatio",
			"induction" => "isForcedInduction"
	);
	foreach ($eInfo as $ek=>$ei)
	{
		if (preg_match('/<constant.*?name="' . $ei . '"[^>]*>([^<]+)<\/constant>/', $xml, $ret))
		{
			$vars[$ek] = $ret[1];
		}
	}
}

function isLog($files)
{
	$ext = pathinfo($files[0]['name'])['extension'];
	return in_array($ext, array("mlg", "msl"));
}

//////////////////////////////

if (!$isEmbedded)
{
	$msqur->header();

	//var_export($_POST);
	//var_export($_FILES);
	echo '<div class="uploadOutput">';

	$fileName = 'files';
} else 
{
	$embResult = array();
	$fileName = 'upload-file';
}

if ($isEmbedded || (isset($_POST['upload']) && isset($_FILES)))
{
	$files = checkUploads(fixFileArray($_FILES[$fileName]));

	if ($rusefi->userid < 1)
	{
		addOutput('error', 'You are not logged into rusEFI forum! Please login <a href="'.$rusefi->forum_login_url.'">here</a>!');
	}
	else if (count($files) != 1)
	{
		//No files made it past the check
		addOutput('error', 'Cannot upload!');
	}
	// upload log file
	else if (isLog($files)) 
	{
		$tune_id = isset($_POST['tune_id']) ? intval($_POST['tune_id']) : -1;

		// check if tune_id belongs to current user
		if (!$rusefi->checkIfTuneIsValidForUser($rusefi->userid, $tune_id))
		{
			addOutput('error', 'Cannot find the specified tune!');
		} else
		{
			$error = "";
			$fileList = $msqur->addLog($files[0], $rusefi->userid, $tune_id, $error);
			if ($fileList != null)
			{
				addOutput('info', 'The log file has been uploaded!');

				$fList = '<ul id="fileList">';
				foreach ($fileList as $id => $name)
				{
					$fList .= '<li><a href="view.php?log=' . $id . '">' . "$name" . '</a></li>';
				}
				$fList .= '</ul>';
				addOutput('info', $fList);
					addOutput('info', 'Thank you!');
			}
			else
			{
				addOutput('error', 'Unable to upload the log file: ' . $error);
			}
		}
	}
	else if (!$rusefi->checkIfTuneCanBeUploaded($files, -1, $err_msg))
	{
		addOutput('error', $err_msg);
	}
	else
	{
		if ($isEmbedded)
		{
			$vars = array();
			$xml = isset($files[0]['tmp_name']) ? file_get_contents($files[0]['tmp_name']) : FALSE;
			if ($xml === FALSE)
			{
				addOutput('error', 'Cannot load the file!');
			} else
			{
				fillEngineVarsFromXml($xml, $vars);
			}
		} else
		{
			$vars = $_POST;
		}
		//!!!!!!!!!!!!!!!!!!!!!!!!!
		//print_r($files);
		//$xml = file_get_contents($file['tmp_name']);
		//Convert encoding to UTF-8
		//$xml = mb_convert_encoding($xml, "UTF-8");
		//die;

		$varnames = array('name', 'make', 'code', 'displacement', 'compression', 'induction');
		$notset = array();
		foreach ($varnames as $vn)
		{
			if (!isset($vars[$vn]))
			{
				$notset[] = $vn;
			}
		}
		if (count($notset) > 0)
		{
			addOutput('error', 'Missing Tune variables: ' . implode(',', $notset));
		}
		else 
		{
			if (DEBUG) debug('Adding engine: ' . $vars['name'] . ', ' . $vars['make'] . ', ' . $vars['code'] . ', ' . $vars['displacement'] . ', ' . $vars['compression'] . ', ' . $vars['induction']);
		
			$engineid = $msqur->addOrUpdateVehicle($rusefi->userid, 
				$rusefi->processValue($vars['name']), 
				$rusefi->processValue($vars['make']), 
				$rusefi->processValue($vars['code']), 
				$rusefi->processValue($vars['displacement']),
				$rusefi->processValue($vars['compression']), 
				$rusefi->processValue($vars['induction'])
			);
			$fileList = $msqur->addMSQs($files, $engineid);
		
			$safeName = htmlspecialchars($vars['name'], ENT_COMPAT);
			$safeMake = htmlspecialchars($vars['make'], ENT_COMPAT);
			$safeCode = htmlspecialchars($vars['code'], ENT_COMPAT);
		
			addOutput('info', 'The tune file has been uploaded!');
			
			if ($fileList != null)
			{
				//echo '<div class="info">Successful saved MSQ to database.</div>';
				$fList = '<ul id="fileList">';
				foreach ($fileList as $id => $name)
				{
					$fList .= '<li><a href="view.php?msq=' . $id . '">' . "$safeName ($safeMake $safeCode) - $name" . '</a></li>';
	
					// parse and update DB
					$msqur->view($id, "", array());
				}
				$fList .= '</ul>';
				addOutput('info', $fList);
				addOutput('info', 'Thank you!');
			}
			else
			{
				addOutput('error', 'Unable to store uploaded file.');
			}
		}
	}
} else
{
	addOutput('error' ,'Nothing to upload!');
}

if (!$isEmbedded)
{
	echo '</div>';
	require "browse.php";

	//$msqur->footer();
} else 
{
	echo json_encode($embResult);
}
?>
