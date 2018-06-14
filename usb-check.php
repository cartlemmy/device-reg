#!/usr/bin/php
<?php

define('SYSLOG_LOCATION', '/var/log/syslog');

exec("pgrep usb-check.php", $pids);
if (count(explode("\n",trim(implode("\n",$pids)))) >= 2) {
	echo "usb-check.php already running";
	exit();
}

require_once(__DIR__.'/inc/common.php');
$matchers = array(
	'/usb\s(\d([\.\-]\d+)+)/'=>array('port'=>'$1'),
	'/New USB device found/'=>array('action'=>'new-device'),
	'/USB disconnect/'=>array('action'=>'disconnect'),
	'/Product\: (.*)/'=>array('product'=>'$1'),
	'/Manufacturer:\ (.*)/'=>array('manufacturer'=>'$1'),
	'/SerialNumber:\ (.*)/'=>array('action'=>'scan','serial'=>'$1'),
	'/New USB device string/'=>true,
	'/FIXME:/'=>false,
	'/Device\s(\d+)\s\(VID\=(\d+)\sand\sPID\=(\d+)\)\sis\sa\s(.*).$/'=>array(
		'action'=>'device-id',
		'deviceNum'=>'$1',
		'vid'=>'$2',
		'pid'=>'$3',
		'name'=>'$4',
	)
);

if (getGlobal('adb-restart')) {
	dbg('ADB requires restart');
	adbCommand('adb kill-server');
	clearGlobal('adb-restart');
	exit();
}

verbose('scanning '.SYSLOG_LOCATION);
$callDevCheck = false;
if (($lines = tailFile(SYSLOG_LOCATION, '\susb\s|gvfs\-mtp\-volume|gvfsd')) !== false) {
	$vars = array();
	foreach ($lines as $line) {
		$matched = 0;
		foreach ($matchers as $match=>$set) {
			if (preg_match($match, $line, $m)) {
				if ($set === false) break;
				if ($set === true) {
					$callDevCheck = true;
					break;
				}
				if ($matched == 0) verbose('\tmatched: '.$line);
				$matched ++;
				verbose($match);
				verbose(str_replace("\n","\n\t\t", json_encode($m, JSON_PRETTY_PRINT)));
				foreach ($set as $n=>$v) {
					if ($n === 'dbg-label') {
						dbg($v);
					} elseif (substr($v,0,1) == '$') {
						$vars[$n] = $m[(int)substr($v,1)];
					} else {
						$vars[$n] = $v;
					}
				}
				if (isset($vars["action"])) {
					// Probably shouldn't check the serial # this way
					/*if (isset($vars["port"]) && !isset($vars["serial"])) {
						$infoFile = 'dev/'.$vars["port"];
						if (is_file($infoFile) && ($info = json_decode(file_get_contents($infoFile), true)) !== false) {
							if (isset($info["serial"])) { 
								$vars["serial"] = $info["serial"];
							}
						}
					}*/
					
					switch ($vars["action"]) {
						case "new-device":
							file_put_contents('new-device', 0);
							if (!isset($vars["serial"])) {
								continue 2;
							}
							//file_put_contents('dev/'.$vars["port"], json_encode($vars, JSON_PRETTY_PRINT));
							break;
						
						case "device-id":	
						case "disconnect":
							break;
							
						default:
							continue 2;
					}
					$callDevCheck = true;
					
					verbose("Queueing action ".json_encode($vars));
					file_put_contents('data/actions', json_encode($vars)."\n", FILE_APPEND);
					$vars = array();
				}
			}
		}
		if ($matched == 0) {
			verbose("\tUnmatched: ".$line);
		}
	}
}

if (is_file('first-run')) {
	$callDevCheck = true;
	unlink('first-run');
}

if (is_file('new-device')) {
	$callDevCheck = true;
}

if ($callDevCheck || filesize('data/actions') || !is_file('data/dev-check-ran') || time() > filemtime('data/dev-check-ran') + 30) { 
	//dbg('running dev-check');
	exec('./dev-check.php 2>&1', $out, $rv);
	if ($rv !== 0) dbg(implode("\n", $out));
	touch('data/dev-check-ran');
}
