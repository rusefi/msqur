<?php

global $rusefi;
global $id;
global $dialogId;
global $viewMode;

include_once("view/view_ts_dialog.php");

// security check
if (!empty($dialogId) && !preg_match("/[A-Za-z0-9]+/", $dialogId)) {
	die;
}

if ($viewMode == "ts-dialog") {
	$dlgTitle = printDialog("", $msqMap, $rusefi->msq, $dialogId, FALSE);
	// pass the title back to JS
	echo "<script>dlgTitle = '".$dlgTitle."';</script>\r\n";
	die;
}

ob_start();
?>

<!-- generated by https://loading.io -->
<div id="loading"><i class="fa fa-spinner fa-pulse fa-3x"style="color:#CCC;"></i> </div>

<div id="ts-menu-container">
<div id="ts-menu">
<ul id="menu">
<?php 
	$mi = 1;
	$menuItems = array();
	if (isset($msqMap["menu"]))
	foreach ($msqMap["menu"] as $mn=>$menu) {
		$mn = printTsItem($mn, $clr);
		if ($mn == "Help") continue;
?>
<li class="tsMenuItem"><img class="tsMenuItemImg" src="view/img/ts-icons/menu<?=$mi;?>.png"><span class="tsMenuItemText"><?=$mn;?></span>
<ul>
<?php
		foreach ($menu["subMenu"] as $sm=>$sub) {
			if ($sub == "std_separator") {
				echo "<li class='tsMenuSeparator' type='separator'></li>\r\n";
			} else {
				$menuItems[] = $sub[0];
				$sm = printTsItem($sub[1], $clr);
				$isDisabled = false;
				//if (isset($sub[3]))
				if (isset($sub[3])) {
					try
					{
						// see INI::parseExpression()
						$isDisabled = !eval($sub[3]);
					} catch (Throwable $t) {
						// todo: should we react somehow?
					}
				}
?>
	<li <?=$isDisabled ? "class=\"ui-state-disabled\"":"";?>><a class="tsMenuItemText tsSubMenuItemText" href="#<?=$sub[0];?>" id="<?=$sub[0];?>" tune_id="<?=$id;?>"><?=$sm;?></a></li>
<?php
			}
		}
?>
</ul>
</li>
<?php
	$mi++;
	}
?>
</ul>
</div>
</div>

<div class="ts-dialogs" isAutoOpen="true">

<?php

$menuItems = !empty($dialogId) ? array($dialogId) : array();

foreach ($menuItems as $mi) {
	if (isset($msqMap["dialog"][$mi])) {
		$dlg = $msqMap["dialog"][$mi];
		$dlgName = $dlg["dialog"][0][0];
		$dlgTitle = getDialogTitle($msqMap, $dlg);
?>
<div class="tsDialog" id="dlg<?=$dlgName;?>" title="<?=$dlgTitle;?>">
<?php
		printDialog("", $msqMap, $rusefi->msq, $mi, FALSE);
?>
</div>
<?php
	}
}
?>

</div>

<script src="view/ts.js"></script>


<?php

$html["ts"] = ob_get_contents();
ob_end_clean();

?>