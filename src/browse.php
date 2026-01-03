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

$page = parseQueryString('p') || 0;
$bq = array();
$bq['user_id'] = parseQueryString('user_id');
$bq['name'] = parseQueryString('vehicleName');
$bq['make'] = parseQueryString('engineMake'); //TODO Define these API method/strings in one place
$bq['code'] = parseQueryString('engineCode');
$bq['firmware'] = parseQueryString('firmware');
$bq['signature'] = parseQueryString('fwVersion'); //TODO might make dependant on firmware
$showAllTunes = parseQueryString('showAll');
//TODO Move column magic strings to some define/static class somewhere

//TODO Use http_build_query and/or parse_url and/or parse_str

function actionStartHeader($requireAdmin = true)
{
	global $msqur, $rusefi;

	$msqur->header();

	echo "<script>var newURL = location.href.split('?')[0]; window.history.replaceState(null, null, newURL);</script>";
	echo '<div class="uploadOutput">';
	if ($requireAdmin && !$rusefi->isAdminUser)
	{
		echo '<div class="error">Only administrators can do this, sorry!</div>';
		return false;
	}
	return true;
}

$action = parseQueryString('action') ?? "";
if ($action == "delete")
{
	$tune_id = isset($_GET['msq']) ? intval($_GET['msq']) : -1;
	$log_id = isset($_GET['log']) ? intval($_GET['log']) : -1;
	unset($_GET['msq']);
	unset($_GET['log']);
	
	if (!actionStartHeader())
	{
	}
	else if ($tune_id <= 0 && $log_id <= 0)
	{
		echo '<div class="error">Cannot delete the unknown item!</div>';
	}
	else
	{
		if (!$msqur->db->deleteFile($tune_id, $log_id))
			echo '<div class="error">Error while deleting the item!</div>';
		else
			echo '<div class="info">The item has been deleted!</div>';
	}
	echo '</div>';
	
} else if ($action == "engines_cleanup")
{
	if (!actionStartHeader())
	{
	}
	else
	{
		$cnt = $msqur->db->deleteUnusedEngines();
		if ($cnt === FALSE)
			echo '<div class="error">Error while deleting the unused engines!</div>';
		else
			echo '<div class="info">'. $cnt . ' engine(s) has been deleted!</div>';
	}
	echo '</div>';
	
} else if ($action == "update_tune_crc")
{
	$tune_id = isset($_GET['msq']) ? intval($_GET['msq']) : -1;
	echo "Calculating CRC and updating DB for ".($tune_id < 0 ? "all tunes" : "ID=".$tune_id)." (please wait!)...\r\n";
	$msqur->db->updateTuneCrc($tune_id);
	die;
} else if ($action == "update_log_crc")
{
	$log_ids = isset($_GET['log']) ? array_map('intval', explode(':', $_GET['log'])) : array();
	if (count($log_ids) == 1) {
		$log_ids[1] = $log_ids[0];
	}
	if (count($log_ids) == 2) {
		for ($log_id = $log_ids[0]; $log_id <= $log_ids[1]; $log_id++) {
			echo "Processing log $log_id and updating DB (please wait!)...<br>\r\n";
			$msqur->db->updateLogDataPoints($log_id);
			echo "Done!\r\n";
		}
	} else {
		echo "Error! Please specify log ID!\r\n";
	}
	die;
} else if ($action == "hide")
{
	$tune_id = isset($_GET['msq']) ? intval($_GET['msq']) : -1;
	$log_id = isset($_GET['log']) ? intval($_GET['log']) : -1;
	unset($_GET['msq']);
	unset($_GET['log']);

	actionStartHeader(false);
	
	if ($tune_id <= 0 /*&& $log_id <= 0*/)
	{
		echo '<div class="error">Cannot hide the unknown tune!</div>';
	}
	else
	{
		if (!$msqur->db->hideTune($tune_id))
			echo '<div class="error">Error while hiding the item!</div>';
		else
			echo '<div class="info">The item has been hidden!</div>';
	}
	echo '</div>';
	
} else
{
	$msqur->header();
}



//require "view/browse.php";
?>
<div class="browse" id="categories">
	<?php //TODO Make a categories function to reduce these
	if ($bq['make'] === null) {
		echo '<div>Makes: <div class="category" id="makes">';
		foreach ($msqur->getEngineMakeList() as $m) { ?>
			<div>
				<?php echo "<a href=\"?engineMake=$m\">$m</a>"; ?>
			</div>
		<?php
		}
		echo '</div>';
	}
	
	if ($bq['code'] === null)
	{
		echo '<div>Engine Codes: <div class="category" id="codes">';
		foreach ($msqur->getEngineCodeList() as $m) { ?>
			<div>
				<?php echo "<a href=\"?engineCode=$m\">$m</a>"; ?>
			</div>
		<?php
		}
		echo '</div>';
	}
/*	
	if ($bq['firmware'] === null)
	{
		echo '<div>Firmware: <div class="category" id="firmware">';
		foreach ($msqur->getFirmwareList() as $m) { ?>
			<div>
				<?php echo "<a href=\"?firmware=$m\">$m</a>"; ?>
			</div>
		<?php
		}
		echo '</div>';
	}
*/	
/*
	if ($bq['signature']=== null)
	{
		echo '<div>Firmware Versions: <div class="category" id="versions">';
		foreach ($msqur->getFirmwareVersionList() as $m) { ?>
			<div>
				<?php echo "<a href=\"?fwVersion=$m\">$m</a>"; ?>
			</div>
		<?php
		}
		echo '</div>';
	}
*/
	?>
</div>
<!-- script src="view/browse.js"></script -->
<?php

function printTuneComment($c, $isTooltip)
{
	// decode from xml format
	$c = html_entity_decode($c, ENT_COMPAT);
	// compact to 1 line
	if (!$isTooltip) {
		$c = preg_replace("/\s+/", " ", $c);
		$c = preg_replace("/^<html>/", "", $c);
	}
	// for tooltips we show all, otherwise limit the length
	$limit = 32;
	$isTooLong = strlen($c) > $limit;
	// protect from injecting html tags etc.
	$c = htmlentities($c, ENT_COMPAT);
	// preserve newlines for extended tooltip version
	if ($isTooltip) {
		$c = preg_replace("/[\r\n+]/", "<br/>", $c);
		return $isTooLong ? $c : "";
	}
	// add ellipsis
	return $isTooLong ? (substr($c, 0, $limit) . "&hellip;") : $c;
}

function putResultsInTable($results, $type)
{
	global $rusefi;

	if (!is_array($results))
		return;
	$numResults = count($results);

	$headers = array(
		"msq" => array("", "uploaded"=>"Uploaded", "Owner", "Vehicle Name", "Engine Make/Code", "Tune Note", "Cylinders",
					"Liters", "Compression", "Aspiration", /*"Firmware/Version", */"Views", "Options"),
		"log" => array("uploaded"=>"Uploaded", "Owner", "Duration", "Views", "Options"),
	);

	$ttype = ucfirst($type);

	echo '<div id="content'.$ttype.'">'; //<div class="info">' . $numResults . ' results.</div>';
	echo '<div id="container'.$ttype.'">';
	if ($type == "msq") {
		echo '<div class="diff-tune-container"><input type="button" id="diffTuneButton" value="Compare" title="Please select two tunes to compare using the checkboxes on the left"/></div>';
	}
	echo '<table id="browse'.$ttype.'Results" ng-controller="BrowseController">';
	echo '<thead><tr class="theader">';
	foreach ($headers[$type] as $hn=>$h) {
		echo '<th' . (is_string($hn) ? ' id="uploaded'.$ttype.'"' : '') . ">$h</th>\r\n";
	}
	echo '</tr></thead>';
	echo '<tbody>';
	for ($c = 0; $c < $numResults; $c++)
	{
    	$res = $results[$c];
		$viewUrlClass = "";
		echo '<tr>';
		if ($type == "msq") {
			$checked = ($c < 2) ? "checked" : "";	// check first two
			echo '<td><input class="diff-tune-check" type="checkbox" name="diffTune" value="' . $res['mid'] . '" '.$checked.'/></td>';
			$viewUrlClass = $res['hidden'] == 1 ? "class=hiddenUrl" : "";
		}
		echo '<td><a '.($viewUrlClass).' href="view.php?' . $type . '=' . $res['mid'] . '">' . $res['uploadDate'] . '</a></td>';
		echo '<td><a href="'.$rusefi->getUserProfileLinkFromId($res['user_id']) . '">' . $rusefi->getUserNameFromId($res['user_id']) . '</a></td>';
		if ($type == "msq")
		{
			echo '<td><a href="?vehicleName='.urlencode($res['name']).'&user_id='.$res['user_id'].'">' . $res['name'] . '</a></td>';
			echo '<td>' . $res['make'] . ' ' . $res['code'] . '</td>';
			echo '<td><div class=tuneComment title="' . printTuneComment($res['tuneComment'], true) . '">' . printTuneComment($res['tuneComment'], false) . '</div></td>';
			echo '<td>' . $res['numCylinders'] . '</td>';
			echo '<td>' . $res['displacement'] . '</td>';
			echo '<td>' . $res['compression'] . ':1</td>';
			$aspiration = $res['induction'] == 1 ? "Turbo" : "Atmo";
			echo '<td>' . $aspiration . '</td>';
			//echo '<td>' . $res['firmware'] . '/' . $res['signature'] . '</td>';
		}
		else if ($type == "log")
		{
			echo '<td>' . (isset($res['duration']) ? $res['duration'] : '?') . '</td>';
		}
		echo '<td>' . $res['views'] . '</td>';
		echo '<td><a class="downloadLink" title="Download ' . strtoupper($type). '" download href="download.php?' . $type . '=' . $res['mid'] . '">💾</a>';
		$isOwner = ($res["user_id"] == $rusefi->userid);
		if ($rusefi->isAdminUser || ($isOwner && $type == "msq")) {
			$delAction = $rusefi->isAdminUser ? "delete" : "hide";
			$delName = $rusefi->isAdminUser ? "Delete" : "Hide";
			echo ' <a class="deleteLink" title="'.$delName.' ' . strtoupper($type). '" info="'.$res['uploadDate'].' '.$res['name'].'" action="'.$delAction.'" href="?action='.$delAction.'&' . $type . '=' . $res['mid'] . '">❌</a>';
		}
		echo "</td></tr>\r\n";
	}
	echo '</tbody></table></div></div>';
}

$topic_id = $rusefi->getForumTopicId($bq['user_id'], $bq['name']);
if ($topic_id > 0) {
	echo "<div><a href=\"/forum/viewtopic.php?t=".$topic_id."\">More about ".$bq['name']." on the forum</a></div>\r\n";
}

$showAll = array_filter($bq) ? true : false;	// if no additional args
if ($rusefi->isAdminUser && $showAllTunes)
	$showAll = true;
$showHidden = $rusefi->isAdminUser ? true : false;
$resultsMsq = $msqur->browse($bq, $page, "msq", $showAll, $showHidden);
if ($showAll) {
	echo '<div>Tunes:';
} else {
	echo '<div>Latest tunes: (<a href="?showAll=true">Show All</a>)';
}
putResultsInTable($resultsMsq, "msq");
echo '</div>';

$bqForLogs = array();
$bqForLogs['e.user_id'] = $bq['user_id'];
$bqForLogs['name'] = $bq['name'];
$resultsLog = $msqur->browse($bqForLogs, $page, "log");
$rusefi->unpackLogInfo($resultsLog, true);
echo '<div>Logs:';
putResultsInTable($resultsLog, "log");
echo '</div>';

$msqur->footer();
?>
