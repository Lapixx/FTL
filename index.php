<?php

# FTL configuration
$ftl_password = 'ADMIN';

$pwhash = substr(md5($ftl_password.'299,792,458'), 0, 10);

function createBackup($force = false){

	chdir(dirname(__FILE__));
	
	//echo 'DBG: Starting...<br>';

	if(file_exists('preferences.json')){
		$json = json_decode(file_get_contents('preferences.json'), true);
	}
		
	//echo 'DBG: Backup active?<br>';
	
	if($json['interval'] == 0 && $force == false){ // Backup disabled
		exit;
	}
	
	$span[1] = 60*60*24; // Every day
	$span[2] = 60*60*24*7; // Every week
	$span[3] = 60*60*24*30; // Every month
	$span[4] = 60*60*24*30*3; // Every 3 months

	//echo 'DBG: Backup required?<br>';
		
	$then = getdate($json['lastbackup']);
	$then_stamp = mktime(0, 0, 0, $then['mon'], $then['mday'], $then['year']);
	
	if(time() - $then_stamp < $span[$json['interval']] && $json['lastbackup'] != -1 && $force == false){
		exit; // No backup needed
	}
	
	//echo 'DBG: Backing up files...<br>';
	
	set_time_limit(0);
	
	$uuid = uniqid('FTL_');
	$achieve_name = 'backups/'.$uuid.'.zip.part';
	$n = 2;
	while(file_exists($achieve_name)){
		$achieve_name = 'backups/'.$uuid.'_'.$n.'.zip.part';
		$n++;
	}
	
	$dirs = array('..');
	
	$total_dirs = 0;
	$total_files = 0;
	
	$count = 0;
	$zip = new ZipArchive; 
	$open = $zip->open($achieve_name, ZipArchive::OVERWRITE);
	if($open === true){
		while(count($dirs) > 0){
			$dir = array_shift($dirs);
			$total_dirs++;
			
			$zip->addEmptyDir($dir);
			
			$scan = scandir($dir);
			foreach($scan AS $file){
				if($file != '.' && $file != '..'){
					if(is_file($dir.'/'.$file)){
						$zip->addFile($dir.'/'.$file, $dir.'/'.$file);
						$total_files++;
						if(($count++) == 200){
							//echo 'DBG: 200 files added - reopening ZIP';
							$zip->close();
							$zip->open($achieve_name);
							$count = 0;
						}
					}
					elseif(is_dir($dir.'/'.$file)){
						if($dir != '..' || (!in_array($file, $json['skip'])) && $file != basename(realpath('.'))){
							array_push($dirs, $dir.'/'.$file);
						}
					}
				}
			}
		}
		$zip->close();
				
		$new_name = 'backups/FTLBackup '.date('d-m-y').'.zip';
		$n = 2;
		while(file_exists($new_name)){
			$new_name = 'backups/FTLBackup '.date('d-m-y').' ('.$n.').zip';
			$n++;
		}
		
		rename($achieve_name, $new_name);
	}
	
	//echo 'DBG: Created backup: '.$achieve_name.'<br>';
	
	// Remove old backups
	$backups = array();
	$dates = array();
	$space = 0;
	
	$scan = scandir('backups');
	foreach($scan AS $file){
		if($file != '.' && $file != '..'){
			array_push($backups, 'backups/'.$file);
			array_push($dates, filemtime('backups/'.$file));
			$space += filesize('backups/'.$file);
		}
	}
	array_multisort($dates, SORT_NUMERIC, SORT_DESC, $backups);
	
	//echo 'DBG: Storage useage: '.$space.'B of '.($json['storage'] * 1048576).'B ('.round($space / ($json['storage'] * 1048576) * 100).'%)<br>';
	
	while($space > $json['storage'] * 1048576){
		$tgt_file = array_pop($backups);
		$space -= filesize($tgt_file);
		unlink($tgt_file);
		//echo 'DBG: Removed backup: '.$tgt_file.' - '.$space.'B in use<br>';
	}
	
	if($force == false){
		$json['lastbackup'] = time();
		file_put_contents('preferences.json', json_encode($json));
	}
	
	//echo 'DBG: Done';
	if($force){
		return array($total_files, $total_dirs);
	}
		
}

if(isset($argv[1]) && $argv[1] == '-cronbackup' && isset($argv[2]) && $argv[2] == $pwhash){
//if(isset($_GET['cronos'])){
	createBackup();
	exit;
}

session_start();
header('Content-type: text/html; charset=utf-8');

$action = 0;

$subtitle[0] = 'Authorize';
$subtitle[1] = 'Dashboard';
$subtitle[2] = 'Configuration';
$subtitle[3] = 'Restore';
$subtitle[4] = 'Install';
$subtitle[5] = 'Backup';

$hostname = ((strtolower(substr($_SERVER['HTTP_HOST'], 0, 4)) == 'www.') ? substr($_SERVER['HTTP_HOST'], 4) : $_SERVER['HTTP_HOST']);

$json = array();
if(file_exists('preferences.json')){
	$json = json_decode(file_get_contents('preferences.json'), true);
	if($json === null){
		$json = array();
	}
}
else{
	$alert = 'preferences.json is missing';
}

if(isset($_SESSION['auth'])){
	
	if(isset($_POST['postback']) && $_POST['postback'] != '' && $_POST['token'] == $pwhash){
		$action = 1;
		switch($_POST['postback']){
			
			case 'config':
				
				$lastbackup = -1;
				if($_POST['renew'] != 1){
					if(array_key_exists('lastbackup', $json)){
						$lastbackup = $json['lastbackup'];
					}
				}
				
				$json = array(
					'skip' => (array) $_POST['subfolders'],
					'interval' => (int) $_POST['interval'],
					'storage' => (int) $_POST['storage'],
					'lastbackup' => $lastbackup
				);
							
				if(@file_put_contents('preferences.json', json_encode($json))){
					$alert = 'Your preferences have been saved!';
				}
				else{
					$alert = 'Could not save settings - chmod <b>preferences.json</b> to <b>777</b>';
				}
				
			break;
			
			case 'revert':
			
				$alert = 'Could not restore website';
				
				$rev = 'backups/'.$_POST['revert'];
				if(file_exists($rev)){
					$zip = new ZipArchive; 
					if($zip->open($rev) === true){
						$restore = array();
						for($i = 0; $i < $zip->numFiles; $i++){
							$fname = $zip->getNameIndex($i);
							if(!file_exists($fname)){
								array_push($restore, $fname);
							}
						}
						if(count($restore) > 0){
							@$zip->extractTo('..', $restore);
						}
						if(error_get_last() == null){
							$file = (count($restore) == 1) ? 'file' : 'files';
							$have = (count($restore) == 1) ? 'has' : 'have';
							$alert = count($restore).' '.$file.' '.$have.' been restored!';
						}
						$zip->close();
					}
				}		
						
			break;
			
			case 'backupnow':
				ignore_user_abort(true);
				$ti = microtime(true);
				$totals = createBackup(true);
				$dt = round(microtime(true) - $ti);
				ignore_user_abort(false);
				
				$mins = floor($dt / 60);
				$secs = $dt % 60;
				if($secs < 10){
					$secs = '0'.$secs;
				}
				$minsec = $mins.':'.$secs;
				
				$alert = 'Backup completed in '.$minsec.' - '.$totals[0].' files in '.$totals[1].' folders';
			break;
				
		}
	}
	else{
		$action = 1;
		if(isset($_GET['action'])){
			$a = (int) $_GET['action'];
			if($a == 42){
				session_destroy();
				$action = 0;
			}
			elseif($a > 1 && $a < count($subtitle)){
				$action = $a;
			}
		}
	}
}
else if(isset($_POST['password'])){
	if($_POST['password'] == $ftl_password){
		$_SESSION['auth'] = true;
		$action = 1;
	}
}
?><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">

<html>
<head>
<title>FTL / <?php echo $hostname; ?></title>

<style type="text/css">

body{
	background-color: #FDFDFD;
	color: #000000;
	padding: 0px;
	margin: 0px;
	font-family: Verdana, sans-serif;
	font-size: 12px;
	text-align: center;
}
.headstroke{
	background-color: #262626;
	color: #FFFFFF;
	font-family: Georgia, serif;
	font-size: 14px;
	font-style: italic;
	-moz-user-select: none;
	-khtml-user-select: none;
	user-select: none;
	padding: 0px;
	margin: 0px;
	height: 16px;
	cursor: default;
	margin-bottom: 16px;
}
.headstroke.alert{
	padding-top: 8px;
	padding-bottom: 8px;
	margin-bottom: 0px;
}
.wrap{
	padding: 20px;
	text-align: left;
	width: 800px;
	margin-left: auto;
	margin-right: auto;
}

div.head{
	margin-top: 50px;
	margin-bottom: 50px;
	-moz-user-select: none;
	-khtml-user-select: none;
	user-select: none;
	cursor: default;
	position: relative;
	left: -20px;
}

div.title{
	color: #FFFFFF;
	background-color: #EE4400;
	font-family: Georgia;
	font-style: italic;
	font-size: 30px;
	padding: 0px 8px;
	height: 40px;
	line-height: 40px;
	display: inline-block;
}
div.title.sub{
	font-size: 20px;
	background-color: #262626;
	font-style: normal;
	height: 24px;
	line-height: 24px;
}

.arrow{
	border-color: transparent transparent transparent #EE4400;
	border-style: solid;
	border-width: 20px;
	width: 0px;
	height: 0px;
	display: inline-block;
	vertical-align: top;
}
.arrow.sub{
	border-color: transparent transparent transparent #262626;
	border-width: 12px;
}

a{
	color: #EE4400;
}
a:hover{
	text-decoration: none;
}

input[type=text], input[type=password]{
	font-family: Verdana, sans-serif;
	background-color: #FFFFFF;
	width: 200px;
	padding: 4px;
	border: 1px solid #000000;
	outline: none;
}
input[type=text]:focus, input[type=password]:focus{
	background-color: #FFFFE0;
}

input[type=submit], input[type=button], a.button{
	font-family: Verdana, sans-serif;
	padding: 4px 8px;
	margin: 2px;
	border: none;
	outline: none;
	color: #FFFFFF;
	background-color: #262626;
	font-weight: bold;
	font-size: 12px;
	cursor: pointer;
	text-decoration: none;
	position: relative;
	left: -3px;
}
input[type=submit]:hover, input[type=button]:hover, a.button:hover{
	background-color: #EE4400;
}

input[type=submit].fog, input[type=button].fog{
	background-color: #CCCCCC;
}

hr{
	color: #FDFDFD;
	background-color: #FDFDFD;
	border-width: 0px;
	border-bottom: 1px dotted #262626;
	margin-top: 50px;
}

<?php if($action != 0): ?>

ol{
	color: #AAAAAA;
	font-weight: bold;
}
ol span{
	color: #000000;
	font-weight: normal;
}

.pointy{
	border-color: transparent transparent transparent #262626;
	border-style: solid;
	border-width: 3px;
	width: 0px;
	height: 0px;
	display: inline-block;
	position: relative;
	vertical-align: middle;
	margin-left: 10px;
}

input[type=text].short, input[type=password].short{
	width: 35px;
	text-align: right;
}

input[type=text].xl, input[type=password].xl{
	border: none;
	background-color: #DDDDDD;
	width: 500px;
}

select{
	background-color: #FFFFFF;
	padding: 4px;
	border: 1px solid #000000;
	outline: none;
}

div.selectlist{
	background-color: #FFFFFF;
	border: 1px solid #BFBFBF;
	overflow: auto;
	overflow-x: hidden;
	width: 400px;
	height: 200px;
	display: inline-block;
	margin: 5px 0px 5px;
}

table{
	font-size: 12px;
	border-collapse: collapse;
}

table td{
	padding: 0px;
	padding-right: 6px;
}

div.selectlist table{
	width: 100%;
}

div.selectlist table td{
	padding: 0px;
	margin: 0px;
	height: 25px;
	line-height: 25px;
}

table tr.odd td{
	background-color: #F2F6FA;
}

table td.small{
	width: 20px;
}

table td label{
	display: block;
	height: 100%;
	cursor: pointer;
}

table td label i{
	color: #AAAAAA;
	font-style: normal;
}


div.warn{
	display: inline-block;
	width: 400px;
	height: 200px;
	text-align: center;
	line-height: 200px;
	font-size: 20px;
	font-style: italic;
	color: #AAAAAA;
}

.progress{
	width: 400px;
	height: 5px;
	background: #D0D0D0;
	margin-top: 4px;
	margin-bottom: 4px;
}
.progress div{
	height: 5px;
	background: #EE4400;
}

a.fat{
	position: relative;
	left: 20px;	
}
a.fat div.block{
	color: #EE4400;
	padding: 0px 6px;
	height: 24px;
	line-height: 24px;
	display: inline-block;
	margin-bottom: 5px;
}
a.fat div.arrow{
	border-color: transparent transparent transparent transparent;
	border-style: solid;
	border-width: 12px;
	width: 0px;
	height: 0px;
	display: inline-block;
	vertical-align: top;
}
a.fat:hover div.block{
	color: #FFFFFF;
	background-color: #262626;
}
a.fat:hover div.arrow{
	border-color: transparent transparent transparent #262626;
}
<?php endif; ?>

</style>

<script type="text/javascript">

<?php if($action == 0): ?>

window.onload = function(){
	document.getElementById('pass').focus();
}

<?php elseif($action == 2): ?>

function selectNone(){
	boxes = document.getElementsByName('subfolders[]');
	for(i = 0; i < boxes.length; i++){
		boxes[i].checked = false;
	}
}
function selectAll(){
	boxes = document.getElementsByName('subfolders[]');
	for(i = 0; i < boxes.length; i++){
		boxes[i].checked = true;
	}
}

<?php elseif($action == 3): ?>

function checkRadios(){
	boxes = document.getElementsByName('revert');
	for(i = 0; i < boxes.length; i++){
		if(boxes[i].checked){
			return boxes[i].value;
		}
	}
	return false;
}

function showProgress(me){
	if(checkRadios() === false){
		return false;
	}
		
	if(window.confirm("Are you sure you want to restore your website to this date?")){
		me.className = "fog";
		document.getElementById('revertpick').style.display = "none";
		document.getElementById('progressind').innerText = "Reverting, please wait...";
		document.getElementById('progressind').style.display = "inline-block";
		return true;
	}
	else{
		return false;
	}
}

function getDownload(){
	a = checkRadios();
	if(a === false){
		return false;
	}
	window.location = "backups/"+a;
}

<?php endif; ?>

</script>

</head>

<body>

<?php
if(isset($alert) && $alert != ''){
	echo '<div class="headstroke alert">';
	echo $alert;
}
else{
	echo '<div class="headstroke">';
	echo '&nbsp;';
}
echo '</div>';
?>

<div class="wrap">

<div class="head">
<a href="index.php" title="Return to dashboard"><div class="title">FTL / <?php echo $hostname; ?></div><div class="arrow">&nbsp;</div></a><br>
<div class="title sub"><?php echo $subtitle[$action]; ?></div><div class="arrow sub">&nbsp;</div>
</div>

<?php if($action == 0): ?>

<form action="index.php" method="post">
<label for="pass">Password:</label><br>
<input type="password" id="pass" name="password"><input type="submit" value="sign in">
</form>

<?php elseif($action == 1): ?>

<!-- helios5:placemarker -->

<a href="index.php?action=2" class="fat"><div class="block">Configuration</div><div class="arrow">&nbsp;</div></a><br>
<a href="index.php?action=3" class="fat"><div class="block">Restore</div><div class="arrow">&nbsp;</div></a><br>
<a href="index.php?action=5" class="fat"><div class="block">Backup</div><div class="arrow">&nbsp;</div></a><br>
<a href="index.php?action=4" class="fat"><div class="block">Install</div><div class="arrow">&nbsp;</div></a><br>
<a href="index.php?action=42" class="fat"><div class="block">Exit</div><div class="arrow">&nbsp;</div></a>

<?php elseif($action == 2): ?>

<?php
function findDirs($root){
	$dirs = array();
	$scan = scandir($root);
	foreach($scan AS $dir){
		if($dir != '.' && $dir != '..' && is_dir($root.'/'.$dir) && $dir != basename(realpath('.'))){
			array_push($dirs, $root.'/'.$dir);
		}
	}
	return $dirs;
}

function parseOptions($values, $selected){
	foreach($values AS $val => $txt){
		$s = ($selected == $val) ? ' SELECTED' : '';
		echo '<option value="'.$val.'"'.$s.'>'.$txt.'</option>';
	}
}

$skip = array();
if(array_key_exists('skip', $json)){
	$skip = $json['skip'];
}

$root = '..';
$files = findDirs($root);

natcasesort($files);

echo '<form action="index.php" method="post">';

echo '<input type="hidden" name="postback" value="config">';
echo '<input type="hidden" name="token" value="'.$pwhash.'">';

echo '<b title="These subfolders will not be included in the periodical backup (the FTL root folder is automatically ignored)">Subfolders to ignore:</b> (<a href="javascript: selectNone()">none</a>, <a href="javascript: selectAll()">all</a>)<br>';

echo '<div class="selectlist">';
echo '<table>';

$n = 1;
foreach($files AS $file){
	$n++;
	if($n % 2 == 0){
		echo '<tr class="odd">';
	}
	else{
		echo '<tr>';
	}
	
	$check = '';
	if(in_array(basename($file), $skip)){
		$check = ' CHECKED';
	}
	
	echo '<td class="small"><input type="checkbox" id="curf'.$n.'" name="subfolders[]" value="'.basename($file).'"'.$check.'></td>';
	echo '<td><label for="curf'.$n.'">'.basename($file).'</label></td>';
	echo '</tr>';
}

echo '</table>';
echo '</div>';

echo '<table>';

$intervals = array(
	'Disabled',
	'Every day',
	'Every week',
	'Every month',
	'Every 3 months'
);

echo '<tr>';
echo '<td><b>Backup:</b></td>';
echo '<td><select name="interval">';
parseOptions($intervals, $json['interval']);
echo '</select></td>';

echo '</tr><tr>';

echo '<td><b>Storage:</b></td>';
echo '<td><input type="text" name="storage" class="short" value="'.$json['storage'].'"> MB</td>';

/*
echo '</tr><tr>';

$emails = array(
	'Do not send e-mails',
	'E-mail when backup is completed',
	'&nbsp;&nbsp;&#8627;&nbsp;Include backup as attachment'
);

echo '<td><b>E-mail:</b></td>';
echo '<td><select name="email">';
parseOptions($emails, 0);
echo '</select></td>';
*/

echo '</tr><tr>';

echo '<td><b>Renew:</b></td>';
echo '<td><input type="checkbox" name="renew" value="1"></td>';

echo '</tr>';

echo '</table>';

echo '<br><br>';

echo '<input type="submit" value="save settings">';

echo '</form>';
?>

<?php elseif($action == 3): ?>

<?php
function findFiles($root, $ext = ''){
	$size = 0;
	$files = array();
	$scan = scandir($root);
	foreach($scan AS $file){
		if($file != '.' && $file != '..' && is_file($root.'/'.$file)){
			if($ext != ''){
				if(substr($file, -(1+strlen($ext))) != '.'.$ext){
					continue;
				}
			}
			array_push($files, $root.'/'.$file);
			$size += filesize($root.'/'.$file);
		}
	}
	return array($files, $size);
}

$find = findFiles('backups', 'zip');

$backups = $find[0];
$tsize = $find[1];

$dates = array_map('filemtime', $backups);
array_multisort($dates, SORT_NUMERIC, SORT_DESC, $backups);


function convertSize($size){
	$sizes=array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'); 
	for($i=0; $size>1000 && $i<count($sizes)-1; $i++){
    	$size/=1024; 
	}
	if($i<2){
		$size = round($size, 0);
	}
	else{
		$size = round($size, 2);
	}
	$size .= ' '.$sizes[$i];
	return $size;
}

echo '<b>'.convertSize($tsize).'</b> of <b>'.convertSize($json['storage'] * 1048576).'</b> in use';
$p = round(min($tsize / (max($json['storage'] * 1048576, 1)), 1) * 100);
echo '<div class="progress" title="'.$p.'%"><div id="indicate" style="width: '.$p.'%"></div></div>';

if($tsize > $json['storage'] * 1048576){
	echo '<i>FTL will clear up some space during the next scheduled backup</i>';
}

echo '<br><br>';


echo '<b>Versions:</b>';

echo '<form action="index.php" method="post">';

echo '<input type="hidden" name="postback" value="revert">';
echo '<input type="hidden" name="token" value="'.$pwhash.'">';

echo '<div class="selectlist">';

if(count($backups) > 0){
	echo '<table id="revertpick">';	
	$n = 0;
	foreach($backups AS $backup){
		$n++;
		if($n % 2 == 0){
			echo '<tr class="odd">';
		}
		else{
			echo '<tr>';
		}
		echo '<td class="small"><input type="radio" id="revf'.$n.'" name="revert" value="'.basename($backup).'"></td>';
		echo '<td><label for="revf'.$n.'">'.date('l, j F Y <\i>(H:i)<\i>', filemtime($backup)).'</label></td>';
		echo '</tr>';
	}
	echo '</table>';
	echo '<div class="warn" id="progressind" style="display: none"></div>';
}
else{
	echo '<div class="warn">no backups available</div>';
}

echo '</div>';

if(count($backups) > 0){
	echo '<br><br>';
	echo '<input type="submit" onclick="return showProgress(this)" value="revert &#8634;">';
	echo '<input type="button" onclick="return getDownload()" value="download">';
}

echo '</form>';

?>

<?php elseif($action == 4): ?>

<?php
$path = '/usr/local/bin/php';
/*
if(isset($_SERVER['PATH'])){
	$paths = explode(':', $_SERVER['PATH']);
	$path = $paths[0].'/php';
}
*/
$cmd = $path.' -q '.realpath('index.php').' -cronbackup '.$pwhash;
?>

<div class="pointy">&nbsp</div> chmod <b>/ftl/backups/</b> to <b>777</b>.<br>
<div class="pointy">&nbsp</div> chmod <b>/ftl/preferences.json</b> to <b>777</b>.<br>
<div class="pointy">&nbsp</div> Create a new <b>cronjob</b> and make it run every day (<b>0 0 * * *</b>):<br>
<input type="text" class="xl" style="margin-left: 20px" onclick="this.select()" value="<?php echo $cmd; ?>" READONLY><br>
<div class="pointy">&nbsp</div> Use the <a href="index.php?action=2">configuration page</a> to alter your preferences.</span><br>

<?php elseif($action == 5): ?>

<?php

echo '<form action="index.php" method="post">';

echo 'Manually creating a backup of your website might take a while. You do not have to wait for the page to load after starting the backup, although you will not be notified when the process is completed.<br><br>Also note that some webservers will stop responding to other requests while the backup is in progress. This could mean (but not necessarily) that your website can not be visited during the backup process.<br><br>';

echo '<input type="hidden" name="postback" value="backupnow">';
echo '<input type="hidden" name="token" value="'.$pwhash.'">';

echo '<input type="submit" onclick="this.className=\'fog\'" value="Start backup">';

echo '</form>';

?>

<?php endif; ?>

<hr>

<?php
$lastbackup = -1;
if(array_key_exists('lastbackup', $json)){
	$lastbackup = $json['lastbackup'];
}

function relativeDate($date){
	$dt = time() - $date;
	if($dt < 60){
		if($dt == 1){
			return '1 second';
		}
		else{
			return $dt.' seconds';
		}
	}
	elseif($dt < 60*60){
		if(round($dt/60) == 1){
			return '1 minute';
		}
		else{
			return round($dt/60).' minutes';
		}
	}
	elseif($dt < 60*60*24){
		if(round($dt/60/60) == 1){
			return '1 hour';
		}
		else{
			return round($dt/60/60).' hours';
		}
	}
	elseif($dt < 60*60*24*7){
		if(round($dt/60/60/24) == 1){
			return '1 day';
		}
		else{
			return round($dt/60/60/24).' days';
		}
	}
	elseif($dt < 60*60*24*30){
		if(round($dt/60/60/24/7) == 1){
			return '1 week';
		}
		else{
			return round($dt/60/60/24/7).' weeks';
		}
	}
	elseif($dt < 60*60*24*365){
		if(round($dt/60/60/24/30) == 1){
			return '1 month';
		}
		else{
			return round($dt/60/60/24/30).' months';
		}
	}
	else{
		if(round($dt/60/60/24/365) == 1){
			return '1 year';
		}
		else{
			return round($dt/60/60/24/365).' years';
		}
	}
}

echo 'Last backup: ';
echo ($lastbackup != -1) ? relativeDate($lastbackup).' ago' : '-';
?>

</div>

</body>

</html>