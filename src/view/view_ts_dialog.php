<?php

//$html["debug"] = print_r($msqMap["dialog"]["injectionSettings"], TRUE);
//$html["debug"] = print_r($msqMap["dialog"]["injectionBasic"], TRUE);
//$html["debug"] = print_r($msqMap["menu"]["&Base &Engine"], TRUE);

function printTsItem($mn, &$clr) {
	$field = preg_replace(array("/&([A-Za-z])/", "/\"/", "/\\\\n/"), array("<u>$1</u>", "", "\n"), $mn);
	// colorize some labels
	if (!empty($field) && ($field[0] == '#' || $field[0] == '!')) {
		$clr = ($field[0] == '#') ? "ts-label-blue" : "ts-label-red";
		$field = substr($field, 1);
	}
	else {
		$clr = "";
	}
	return $field;
}

// returns array(name, title)
function getDialogTitleSimple($msqMap, $dlg) {
	if (isset($dlg["dialog"]) && is_array($dlg["dialog"])) {
		return array($dlg["dialog"][0], printTsItem($dlg["dialog"][1], $clr));
	}
	return array($dlg["dialog"], "");
}

function getDialogTitle($msqMap, $dlg) {
	$clr = "";
	list($dlgName, $dlgTitle) = getDialogTitleSimple($msqMap, $dlg);
	
	if (empty($dlgTitle)) {
		// take the title from menu
		foreach ($msqMap["menu"] as $menu) {
			foreach ($menu["subMenu"] as $sub) {
				if ($sub[0] == $dlgName) {
					$dlgTitle = printTsItem($sub[1], $clr);
				}
			}
		}
	}
	if (empty($dlgTitle)) {
		// take the title from parent dialog (used by diff)
		foreach ($msqMap["dialog"] as $d) {
			if (isset($d["panel"]) && $d != $dlg) {
				foreach ($d["panel"] as $p) {
					$pName = is_array($p) ? $p[0] : $p;;
					if ($pName == $dlgName) {
						$dlgTitle = getDialogTitle($msqMap, $d);
					}
				}
			}
		}
	}
	return $dlgTitle;
}

function printField($i, $msqMap, $msq, $field, $isPanelDisabled, $idx) {
	global $rusefi;

	$clr = "";
	$cons = NULL;
	if (isset($field[1])) {
		if (isset($msqMap["Constants"][$field[1]])) {
			$cons = $msqMap["Constants"][$field[1]];
		} else if (isset($msqMap["PcVariables"][$field[1]])) {
			$cons = $msqMap["PcVariables"][$field[1]];
		}
	}
	if ($cons !== NULL) {
		$disabled = FALSE;
		// disable the field if required
		if (isset($field[2])) {
			try
			{
				// see INI::parseExpression()
				$disabled = !eval($field[2]);
			} catch (Throwable $t) {
				// todo: should we react somehow?
			}
		}
		// hide the field if required
		$hidden = false;
		if (isset($field[3])) {
			try
			{
				// see INI::parseExpression()
				$hidden = !eval($field[3]);
			} catch (Throwable $t) {
				// todo: should we react somehow?
			}
		}
		if ($hidden)
			return;
		$disabled |= $isPanelDisabled;
		if ($disabled)
			$disabled = " disabled='disabled' ";

		// todo: add edit mode
		$readOnly = "readonly";

		$curValue = $rusefi->getMsqConstantFull($field[1], $msq, $digits);
		$units = "";
		if ($cons[0] == "scalar") {
			$units = printTsItem($cons[3], $clr);
			if (!empty($units)) {
				$units = "(" . $units . ")";
			}
		}

		$hint = "";
		if (isset($msqMap["SettingContextHelp"][$field[1]])) {
			$hint = printTsItem($msqMap["SettingContextHelp"][$field[1]], $clr);
			$hint = " title=\"".$hint."\"";
			//print_r($hint);
			//$hint
		}

		// print hint icon
		echo "<td class='ts-field-td-hint'>";
		if (!empty($hint))
			echo "<img src=\"view/img/ts-icons/hint.png\" ".$hint.">";
		echo "</td>\r\n";

		$highlightClass = ($idx & 1) == 0 ? "ts-field-highlight" : "ts-field-highlight2";
		$hightlight = isset($field["highlight"]) ? " " . $highlightClass : "";

		$fieldId = $field[1] . $i;
		$addToLabel = "";
		// slider is a special case
		if ($field["key"] == "slider") {
			echo "<td colspan=2 class='ts-field-td-label".$hightlight."'><table width='100%' class='ts-field-table' cellspacing='2' cellpadding='2'><tbody><tr>\r\n";
			echo "<td class='ts-field-td-slider'><div class='ts-slider' value='".$curValue."' input='".$field[1]."'></div></td>\r\n";
			echo "<td class='ts-field-td-item'><input id='".$fieldId."' class='ts-field-item-text' value='".$curValue."' >";
			echo "</td></tr><tr>";
			$addToLabel = " colspan=2 style='text-align:center'";
		}

		// print text label
		$labelLext = printTsItem($field[0], $clr);
		echo "<td class='ts-field-td-label".$hightlight."' $addToLabel><label for=".$fieldId." class='ts-field-label $clr' $disabled $readOnly ".$hint.">".$labelLext.$units."</label></td>\r\n";

		// skip the rest
		if ($field["key"] == "slider") {
			echo "</tr></tbody></table></td>";
			return;
		}

		echo "<td class='ts-field-td-item" . $hightlight . "'>\r\n";

		if ($cons[0] == "bits") {
			echo "<select class='ts-field-item ts-field-item-select' id='".$fieldId."' $disabled $readOnly>\r\n";
        	for ($i = 4; $i < count($cons); $i++) {
				$selected = ($curValue == ($i - 4)) ? " selected='selected' " : "";
				if ($cons[$i] != "INVALID") {
					echo "<option class='ts-field-item-option' $selected>".printTsItem($cons[$i], $clr)."</option>\r\n";
				}
        	}
      		echo "</select>\r\n";
		}
		else if ($cons[0] == "scalar") {
			echo "<input id='".$fieldId."' class='ui-spinner-input ts-field-item ts-field-item-text' digits='".$digits."' value='".$curValue."' $disabled $readOnly>";
		}
		else if ($cons[0] == "string") {
			echo "<input id='".$fieldId."' class='ts-field-item' value='".$curValue."' $disabled $readOnly>";
		}
		echo "</td>";
		//print_r($cons);
		return;
	}

	if (is_array($field) && count($field) == 1)
		$field = $field[0];

	if (isset($field["key"]) && $field["key"] == "commandButton") {
		//print_r($field);
		echo "<td class='ts-field-button' colspan='3'><button type=\"button\" class=\"ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only ts-button\">";
		echo "<span class=\"ui-button-text ts-button-text\">". $field[0] . "</span></button></td>\r\n";
	}

	// text field?
	if (is_string($field)) {
		$field = printTsItem($field, $clr);
		echo "<td class='ts-label $clr' colspan='3'>$field</td>\r\n";
	}
}

function printDialog($i, $msqMap, $msq, $dialogId, $isPanel, $isDialogDisabled = false, $showSubPanels = true) {
	global $rusefi;

	$pVClass = "class='ts-controlgroup ts-controlgroup-vertical'";
	$fVClass = "class='ts-panel ts-panel-vertical'";
	$drawCurve = false;

	if (isset($msqMap["dialog"][$dialogId])) {
		$dlg = $msqMap["dialog"][$dialogId];
		$dlgTitle = getDialogTitle($msqMap, $dlg);
	} else if (isset($msqMap["CurveEditor"][$dialogId])) {
		$curve = $msqMap["CurveEditor"][$dialogId];
		$dlgTitle = $curve['desc'];
		$drawCurve = true;
	} else if (isset($msqMap["TableEditor"][$dialogId])) {
		$curve = $msqMap["TableEditor"][$dialogId];
		$dlgTitle = $curve['desc'];
		$drawCurve = true;
	} else {
		return "";
	}

	$isHorizontal = (isset($dlg["dialog"][2]) && $dlg["dialog"][2] == "xAxis");

	// draw fields first, then panels
	// create a fake panel for dialogs without panels
	if (!$isPanel) {
		$fClass = ($i !== "") ? $fVClass : "class='ts-panel-notitle'";
		$pClass = ($i !== "") ? $pVClass : "class='ts-controlgroup ts-controlgroup-vertical'";
?>
<fieldset <?=$fClass;?>>
<?php
		if ($i !== "")
			echo "<legend>$dlgTitle</legend>";
?>
<div <?=$pClass;?>>
<?php
	}

if ($drawCurve) {
	printCurve($i, $msqMap, $msq, $dialogId, $curve);
} else {
?>
<table class='ts-field-table' cellspacing="2" cellpadding="2"><tbody>
<?php
	if (isset($dlg["field"])) {
		$idx = 0;
		foreach ($dlg["field"] as $field) {
			//$f = getDialogTitle($msqMap, $field);
			//print_r($field);
			if (!$isHorizontal) {
?>
<tr>
<?php
			}
			printField($i, $msqMap, $msq, $field, $isDialogDisabled, $idx);
			$idx++;
			if (!$isHorizontal) {
?>
</tr>
<?php
			}
		}
	}
?>
</tbody></table>
<?php
}
	if (!$isPanel) {
?>
</div></fieldset>
<?php
	}

	// draw panels (recursive)
	if (isset($dlg["panel"]) && $showSubPanels) {
		foreach ($dlg["panel"] as $panel) {
			$isDisabled = false;
			if (is_array($panel)) {
				if (isset($panel[1])) {
					if (in_array($panel[1], array("West", "East")))
						$isHorizontal = true;
					try
					{
						// see INI::parseExpression()
						$isDisabled = !eval($panel[1]);
					} catch (Throwable $t) {
						// todo: should we react somehow?
					}
				}
				$panel = $panel[0];
			}
			if (isset($msqMap["dialog"][$panel])) {
				$p = $msqMap["dialog"][$panel];
				$pt = getDialogTitle($msqMap, $p);
			} else {
				$pt = "";
			}
			//print_r($p);
			$pClass = $isHorizontal ? "class='ts-controlgroup ts-controlgroup-horizontal'" : $pVClass;
			$fClass = $isHorizontal ? "class='ts-panel ts-panel-horizontal'" : $fVClass;
			if (!empty($pt)) {
?>
	<fieldset <?=$fClass;?> <?=$isDisabled ? "disabled":"";?>><legend><?=$pt;?></legend>
<?php
			} else {
			$fClass = $isHorizontal ? "class='ts-panel-notitle ts-panel-horizontal'" : "class='ts-panel-notitle'";
?>
	<fieldset  <?=$fClass;?>>
<?php
			}
?>
    <div <?=$pClass;?>>
<?php
			printDialog($i, $msqMap, $msq, $panel, TRUE, $isDisabled);
?>
    </div>
  </fieldset>
<?php
		}
	}

	return $dlgTitle;
}

/**
 * @brief Report curve/table constants the tune does not contain.
 *
 * Constants flagged noMsqSave (e.g. live long term fuel trims) are never saved,
 * so their absence is expected and reported as such rather than as an error.
 * @param $values Constant name => value, where NULL means not found.
 * @returns TRUE when something was missing and a note was printed.
 */
function printCurveMissingData($msqMap, $curve, $values) {
	$notStored = array();
	$missing = array();
	foreach ($values as $name => $value) {
		if ($value !== NULL)
			continue;
		$cons = NULL;
		if (isset($msqMap["Constants"][$name]))
			$cons = $msqMap["Constants"][$name];
		else if (isset($msqMap["PcVariables"][$name]))
			$cons = $msqMap["PcVariables"][$name];
		if (is_array($cons) && in_array("noMsqSave", $cons))
			$notStored[] = $name;
		else
			$missing[] = $name;
	}
	if (empty($notStored) && empty($missing))
		return FALSE;
	$desc = htmlspecialchars($curve['desc']);
	if (!empty($notStored))
		echo "<div class='ts-label'>" . $desc . " is not stored in the tune file (" . htmlspecialchars(implode(', ', $notStored)) . ").</div>";
	if (!empty($missing))
		echo "<div class='error'>Missing data for " . $desc . ": " . htmlspecialchars(implode(', ', $missing)) . "</div>";
	return TRUE;
}

function printCurve($i, $msqMap, $msq, $id, $curve) {
	global $rusefi;

	$help = array_key_exists('topicHelp', $curve) ? $curve['topicHelp'] : NULL;

	echo "<script>	var accordionOptions = {
		animate: false,
		active: true, collapsible: true, //to avoid hooking 'create' to make the first graph render
		heightStyle: 'content',
	};</script>";

	$tabId = "tab_" . $id . $i;
	echo "<div id='".$tabId."'>";

	if (array_keys_exist($curve, 'desc', 'xBinConstant', 'yBinConstant', 'zBinConstant'))
	{
		$digits = array(0, 0, 0);
		$xAxis = $rusefi->getMsqConstantFull($curve['xBinConstant'], $msq, $digits[0]);
		$yAxis = $rusefi->getMsqConstantFull($curve['yBinConstant'], $msq, $digits[1]);
		$zData = $rusefi->getMsqConstantFull($curve['zBinConstant'], $msq, $digits[2]);
		if (!printCurveMissingData($msqMap, $curve, array($curve['xBinConstant'] => $xAxis, $curve['yBinConstant'] => $yAxis, $curve['zBinConstant'] => $zData)))
			echo $msq->msqTable3D($curve, $xAxis, $yAxis, $zData, $help, true, $digits);

	}	
	else if (array_keys_exist($curve, 'desc', 'xBinConstant', 'yBinConstant', 'xMin', 'xMax', 'yMin', 'yMax'))
	{
		$digits = array(0, 0);
		$xAxis = $rusefi->getMsqConstantFull($curve['xBinConstant'], $msq, $digits[0]);
		$yAxis = $rusefi->getMsqConstantFull($curve['yBinConstant'], $msq, $digits[1]);
		if (!printCurveMissingData($msqMap, $curve, array($curve['xBinConstant'] => $xAxis, $curve['yBinConstant'] => $yAxis)))
			echo $msq->msqTable2D($curve, $curve['xMin'], $curve['xMax'], $xAxis, $curve['yMin'], $curve['yMax'], $yAxis, $help, true, $digits);
	}

	echo "</div><script>$('div#".$tabId."').accordion(accordionOptions);
		processChart($('div#".$tabId."'));
		colorizeTables();
		</script>";
	
}

?>