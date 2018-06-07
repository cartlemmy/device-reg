#!/usr/bin/php
<?php

define('SYSLOG_LOCATION', '/var/log/syslog');

exec("pgrep usb-check.php", $pids);
if (count(explode("\n",trim(implode("\n",$pids)))) >= 2) {
	echo "usb-check.php already running";
	exit();
}

$matchers = array(
	'/usb\s(\d([\.\-]\d+)+)/'=>array('dbg-label'=>'USB port found','port'=>'$1'),
	'/New USB device found/'=>array('dbg-label'=>'New device found','action'=>'new-device'),
	'/USB disconnect/'=>array('action'=>'disconnect'),
	'/Product\: (.*)/'=>array('dbg-label'=>'Device Product found', 'product'=>'$1'),
	'/Manufacturer:\ (.*)/'=>array('dbg-label'=>'Device Manufacturer found','manufacturer'=>'$1'),
	'/SerialNumber:\ (.*)/'=>array('dbg-label'=>'Device Serial Number found','serial'=>'$1'),
	'/FIXME:/'=>false,
	'/Device\s(\d+)\s\(VID\=(\d+)\sand\sPID\=(\d+)\)\sis\sa\s(.*).$/'=>array(
		'action'=>'device-id',
		'deviceNum'=>'$1',
		'vid'=>'$2',
		'pid'=>'$3',
		'name'=>'$4',
	)
);

dbg('scanning '.SYSLOG_LOCATION);
$callDevCheck = false;
if (($lines = tailFile(SYSLOG_LOCATION, '\susb\s|gvfs\-mtp\-volume|gvfsd')) !== false) {
	$vars = array();
	foreach ($lines as $line) {
		$matched = 0;
		foreach ($matchers as $match=>$set) {
			if (preg_match($match, $line, $m)) {
				if ($set === false) continue 2;
				if ($matched == 0) echo "\t".$line."\n";
				$matched ++;
				//echo "\t\t".$match."\n";
				//echo "\t\t".str_replace("\n","\n\t\t", json_encode($m, JSON_PRETTY_PRINT))."\n";
				foreach ($set as $n=>$v) {
					if ($n === 'dbg-label') {
					} elseif (substr($v,0,1) == '$') {
						$vars[$n] = $m[(int)substr($v,1)];
					} else {
						$vars[$n] = $v;
					}
				}
				if (isset($vars["action"])) {
					if (isset($vars["port"]) && !isset($vars["serial"])) {
						$infoFile = 'dev/'.$vars["port"];
						if (is_file($infoFile) && ($info = json_decode(file_get_contents($infoFile), true)) !== false) {
							$vars["serial"] = $info["serial"];
						}
					}
					switch ($vars["action"]) {
						case "new-device":
							if (!isset($vars["serial"])) {
								echo "Waiting for device SerialNumber\n";
								continue 2;
							}
							file_put_contents('dev/'.$vars["port"], json_encode($vars, JSON_PRETTY_PRINT));
							break;
						
						case "device-id":	
						case "disconnect":
							break;
							
						default:
							continue 2;
					}
					$callDevCheck = true;
					
					echo "Queueing action ".json_encode($vars)."\n";
					file_put_contents('data/actions', json_encode($vars)."\n", FILE_APPEND);
					$vars = array();
				}
			}
		}
		if ($matched == 0) {
			echo "\tUnmatched: ".$line."\n";
		}
	}
}

if ($callDevCheck || filesize('data/actions')) { 
	system('./dev-check.php');
}

function tailFile($file, $match = false) {
	$rv = array();
	$posFile = 'data/pos-'.preg_replace('/[^\w\d\-]/','_',$file);
	$prevPos = is_file($posFile) ? (int)file_get_contents($posFile) : 0;
	
	if ($fp = fopen($file, "r")) {
		fseek($fp, 0, SEEK_END);
		if ($prevPos > ftell($fp)) $prevPos = 0;
		fseek($fp, $prevPos);
		
		while (!feof($fp)) {
			if (($line = trim(fgets($fp))) !== "") {
				if ($match === false || preg_match('/'.$match.'/', $line)) {
					$rv[] = $line;
				}
			}
		}
		file_put_contents($posFile, ftell($fp));
		fclose($fp);
	}
	return count($rv) ? $rv : false;
}

function dbg($txt) {
	echo $txt."\n";
}
