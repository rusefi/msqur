<!DOCTYPE html>
<html xmlns:og="https://ogp.me/ns#" xmlns:fb="https://www.facebook.com/2008/fbml" lang="en" ng-app="msqur">
<head>
	<title>rusEFI Online</title>
	<meta charset="UTF-8">
	<meta name="description" content="rusEFI tune file sharing site" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<link rel="icon" type="image/x-icon" href="view/img/favicon.ico">
	<link rel="stylesheet" href="view/msqur.css" />
	<link rel="stylesheet" href="view/lib/dynatable/jquery.dynatable.css" />
	<!-- Open Graph data -->
	<meta property="fb:admins" content="xxxtodo"/>
	<meta property="og:title" content="rusEFI online" />
	<meta property="og:type" content="website" />
	<meta property="og:url" content="https://rusefi.com/online/" />
	<meta property="og:image" content="https://rusefi.com/style/logo_100.gif" />
	<meta property="og:description" content="rusEFI tune file sharing site" />
	<meta property="og:site_name" content="rusEFI online" />
	<!-- External scripts -->
	<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js" integrity="sha384-UM1JrZIpBwVf5jj9dTKVvGiiZPZTLVoq4sfdvIe9SBumsvCuv6AHDNtEiIb5h1kU" crossorigin="anonymous"></script>
	<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/themes/smoothness/jquery-ui.css" integrity="sha384-5L1Zwk1YapN1l4l4rYc+1fr3Z0g23LbCBztpq0LQcbDCelzqgFb96BMCFtDwjq/b" crossorigin="anonymous">
	<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.1/jquery-ui.min.js" integrity="sha384-ovZOciNc/R4uUo2fCVS1oDT0vIBuaou1d39yqL4a9xFdZAYDswCgrJ6tF8ShkqzF" crossorigin="anonymous"></script>
	<script src="//ajax.googleapis.com/ajax/libs/angularjs/1.5.2/angular.min.js" integrity="sha384-neqWoCEBO5KsP6TEzfMryfZUeh7+qMQEODngh2KGzau+pMU9csLE2azsvQFa8Oel" crossorigin="anonymous"></script>
	<!-- Hosted scripts -->
	<script src="view/lib/dynatable/jquery.dynatable.js"></script>
	<script src="view/lib/tablesorter/jquery.tablesorter.min.js"></script>
	<script src="view/lib/Chart.js/Chart.min.js"></script>
	<script src="view/lib/pako/pako.min.js"></script>
	<script src="view/msqur.js"></script>
<?php
if (isset($_GET['msq'])) {
?>
	<meta name="robots" content="noindex">
<?php
}
?>
<script>var buttons = $('div#upload').dialog('option', 'buttons');
var hideUpload = <?= ($rusefi->username == "") ? "true" : "false"; ?>;
</script>
	<script src="view/msqur.js"></script>


</head>
<body>
<div id="navigation">
<span>
	<span>
	<a href="/online" rel="preload"><logo><img src='/style/rusefi_online_color.png' width='140'/></logo></a>
	<a href="/online" rel="preload">Browse</a>
	</span>
	<span><a href="search.php">Search</a></span>
	<span style="display:none;"><a>Stats</a></span>
</span>
 <span class="logged">
<?php if($rusefi->username == "") {?>
You are not logged into rusEFI forum! Please login <a href="<?=$rusefi->forum_login_url;?>">here</a>.
<?php } else { ?>
You are logged in as <a href="<?=$rusefi->forum_user_profile_url;?>"><?=$rusefi->username;?></a>
<?php }?>
	<button id="btnUpload"><img src="view/img/upload.svg" alt="Upload" width="16" height="16"><span>Upload</span></button>
 </span>
</div>

<div id="upload" style="display:none;">
<?php if($rusefi->username == "") { ?>You are not logged into rusEFI forum! Please login <a href="<?=$rusefi->forum_login_url;?>">here</a>. <?php } else { ?>
	<form id="engineForm" action="upload.php" method="post" enctype="multipart/form-data">
		<div id="fileDropZone"><label for="fileSelect">Drop files here</label>
			<input required type="file" id="fileSelect" accept=".msq,.msl,.mlg" name="files[]"/>
		</div>
		<output id="fileList"></output>
		<div id="engineInfo" style="display:none;">
			<fieldset>
				<legend>Vehicle Information from your Tune file:</legend>
				<div class="formDiv">
					<label for="name">Vehicle Name:</label>
					<input id="name" name="name" type="text" placeholder="" maxlength="32" style="width:10em;" readonly required/>
				</div>

				<div class="formDiv">
					<label for="make">Engine Make:</label>
					<input id="make" name="make" type="text" placeholder="" maxlength="32" style="width:10em;" readonly required/>
				</div>
				<div class="formDiv">
					<label for="code">Engine Code:</label>
					<input id="code" name="code" type="text" placeholder="" maxlength="32" style="width:10em;" readonly required/>
				</div>
				<div class="formDiv">
					<label for="displacement">Displacement (liters):</label>
					<input id="displacement" name="displacement" type="text" value="" style="width:4em;" readonly/>
				</div>
				<div class="formDiv">
					<label for="compression">Compression Ratio (CR):</label>
					<input id="compression" name="compression" type="text" value="" style="width:4em;" readonly/>
				</div>
				<div class="formDiv">
					<label for="induction">Is forced induction?</label>
					<input id="induction" name="induction" type="text" value="" style="width:4em;" readonly/>
				</div>
				<input type="hidden" name="upload" value="upload" style="display:none;">
			</fieldset>
		</div>
		<div id="logInfo" style="display:none;">
			<fieldset>
				<legend>Information from your Log file:</legend>
				<output id="logFields"></output>
			</fieldset>
		</div>
		<output id="processing"></output>
		<div id="tuneInfo" style="display:none;">
			<fieldset><small>
			<label for="tune_id">Please select the corresponding tune:</label>
			<select id="tune_id" name="tune_id" style="display:block;">
<?php
			foreach ($rusefi->userTunes as $id=>$tune) 
			{
?>
				<option value="<?=$id;?>"><?=$tune;?></option>
<?php			
			}
?>
			</select>
			</small></fieldset>
		</div>
	</form>
<?php } ?>
</div>
<?php
if (isset($_GET['msq'])) {
?>
<div id="settings">
	<img id="settingsIcon" alt="Settings" src="view/img/settings3.png"/>
	<div id="settingsPanel" style="display:none;">
		<label><input id="colorizeData" type="checkbox" />Colorize</label>
		<label><input id="normalizeData" type="checkbox" title="Recalculate VE table values to a 5-250 unit scale"/>Normalize Data</label>
		<label><input id="normalizeAxis" type="checkbox" disabled />Normalize Axis</label>
	</div>
</div>
<div id="downloadLink"><a title="Download MSQ File" href="download.php?msq=<?php echo $_GET['msq']; ?>">💾 Download MSQ</a></div>
<?php
}
else if (isset($_GET['log'])) {
?>
<div id="downloadLink"><a title="Download LOG File" href="download.php?log=<?php echo $_GET['log']; ?>">💾 Download LOG</a></div>
<?php
}
?>