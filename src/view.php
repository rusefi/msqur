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

require "msqur.php";

if (isset($_GET['msq'])) {
	$id = intval($_GET['msq']);
	$viewMode = parseQueryString('view');
	if (empty($viewMode))
		$viewMode = "ts";
	$dialogId = parseQueryString('dialog');
	$settings = explode("|", parseQueryString('settings') ?? "");
	$html = $msqur->view($id, $viewMode, $settings);
	$engine = $rusefi->getEngineFromTune($id);
	if (count($engine) < 1)
		pageError("Tune not found!");
	// get extra tune params
	$tune_id = $id;
	$tuneParams = $msqur->browse(array("m.id"=>$tune_id), 0, "msq");
	if (count($tuneParams) == 1)
		$tuneParams = $tuneParams[0];
	$isOwner = isset($engine["user_id"]) && ($engine["user_id"] == $rusefi->userid);

	$logs = $rusefi->getTuneLogs($id);

	//!!!!!!!!!!!
	//$rusefi->calcCrc($rusefi->msq);
	//die;

	if ($html !== null) {
		if ($viewMode == "ts-dialog") {
			echo $html;
		} else {
			include "view/header.php";
			include "view/tune_note.php";
			include "view/tune_logs.php";
			include "view/more_about_vehicle.php";

			echo $html;
			include "view/footer.php";
		}
	} else {
		unset($_GET['msq']);
		pageError("404 MSQ file not found.");
	}
}
else if (isset($_GET['log'])) {
	$id = intval($_GET['log']);
	$html = $rusefi->viewLog($id, $engine);
	if ($html !== null) {
		include "view/header.php";
		echo $html;
		include "view/footer.php";
	} else {
		unset($_GET['log']);
		pageError("404 LOG file not found.");
	}
} else {
	include "index.php";
}

?>