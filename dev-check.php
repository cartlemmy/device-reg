#!/usr/bin/php
<?php

require('inc/config.inc.php');
$androidData = require('inc/android-data.php');

require('inc/proc.php');

exec("pgrep dev-check.php", $pids);
if (count(explode("\n",trim(implode("\n",$pids)))) >= 2) {
	echo "dev-check.php already running\n";
	exit();
}

if (($devices = getSys(
	'adb devices -l',
	array(
		array(
			'/([A-Za-z0-9]{10,48})\s+device\susb\:(\d([\.\-]\d+)+)/',
			array('serial', 'port')
		)
	)		
)) === false) {
	$devices = array();
}

$noneConnected = !count($devices);;

$notDisconnected = array();
foreach ($devices as &$device) {
	$serArg = escapeshellarg($device["serial"]);
	if (!isset($device["regState"])) $device["regState"] = "new";
	$notDisconnected[] = $device["serial"];
	$device["stateFlags"] = array("usb-tethered"=>1);
	
	$device["tetheredTo"] = CHARGING_STATION.'.'.$device["port"];
	
	if (($batteryRaw = getSys('adb -s '.$serArg.' shell dumpsys battery')) !== false) {
		$device["stateFlags"]["batt-full"] = 0;
		$device["battery"] = array();
		foreach ($batteryRaw as $p) {
			$p = explode(':', $p, 2);
			if (count($p) == 2 && trim($p[1]) !== '') {
				$device["battery"][trim($p[0])] = paramDecode($p[1]);
			}
		}
		
		$device["battery"]["status"] = $androidData["battery"]["status"][$device["battery"]["status"]];
		$device["battery"]["health"] = $androidData["battery"]["health"][$device["battery"]["health"]];
		
		$device["stateFlags"]["batt-".strtolower($device["battery"]["status"])] = 1;
		$device["stateFlags"]["batt-health-".strtolower($device["battery"]["health"])] = 1;
	}
	
	if (($netcfg = getSys(
		'adb -s '.$serArg.' shell netcfg',
		array(
			array(
				'/([A-Za-z0-9]+)\s+(UP|DOWN)\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d+)\s+(0x\d{8})\s+([0-9A-Fa-f]{2}\:[0-9A-Fa-f]{2}\:[0-9A-Fa-f]{2}\:[0-9A-Fa-f]{2}\:[0-9A-Fa-f]{2}\:[0-9A-Fa-f]{2})/',
				array("if","state","ip","netmask","notsure","mac")
			)
		)
	)) !== false) {
		$device["stateFlags"]["lan-connected"] = 0;
		$device["mac"] = array();
		$device["netcfg"] = array();
		foreach ($netcfg as $if) {
			if ($if["mac"] != "00:00:00:00:00:00") {
				$device["netcfg"][] = $if;
				$device["mac"] = $if["mac"];
				if ($if["ip"] != "0.0.0.0") {
					$device["stateFlags"]["lan-connected"] = 1;
					$device["lanIP"] = $if["ip"];
				}
			}
		}
		
	}
	
	$device["rooted"] = 0;
	if (($packages = getSys(
		'adb -s '.$serArg.' shell pm list packages',
		array(
			array(
				'/package\:([\w\d\.]+)/'
			)
		)		
	)) !== false) {
		$device["packages"] = array();	
		if (in_array("com.kingroot.kinguser", $packages)) $device["rooted"] = 1;
		if ($dp = opendir('required-apks')) {
			while (($file = readdir($dp)) !== false) {
				$path = 'required-apks/'.$file;
				if (substr($file,0,1) !== '.' && is_dir($path)) {
					$n = explode('.',$file);
					$n = array_pop($n);				
					if (in_array($file, $packages)) {
						$device["packages"][] = $file;
						$device["stateFlags"][$n.'-installed'] = 1;
					} else {
						$device["stateFlags"][$n.'-installed'] = 0;
						installAPKInDir($device["serial"], $path);
					}					
				}
			}
			closedir($dp);
		}
	}
	//echo '!!! adb -s '.$serArg.' shell pm list packages'."\n";
	//echo "!!! ".print_r($packages, true)."\n";
	if (($wan = getSys(
		'adb -s '.$serArg.' shell ping -c 1 paliportal.com',
		array(array('/1 received/'))
	)) !== false) {
		$device["stateFlags"]["wan-connected"] = count($wan) == 0 || trim($wan[0]) == "" ? 0 : 1;
	}
}
unset($device);

$newActions = array();
if ($fp = @fopen('data/actions', 'r')) {
	while (!feof($fp)) {
		$line = fgets($fp);
		if ($deviceFromAction = json_decode($line, true)) {
			$action = $deviceFromAction["action"];
			if ($noneConnected && !'disconnect') {
				$newActions[] = $line;
				continue;
			}
			unset($deviceFromAction["action"]);
			
			$devI = -1;
			foreach ($devices as $i=>$device) {
				if (isset($device["serial"]) && $device["serial"] == $deviceFromAction["serial"]) {
					$devI = $i;
					break;
				}
			}
			if ($devI == -1) {
				$devI = count($devices);
				$devices[] = $deviceFromAction;
			} else {
				foreach ($deviceFromAction as $n=>$v) {
					$devices[$devI][$n] = $v;
				}
			}
			if (!isset($devices[$devI]["port"])) {
				echo "\tTried Action '".$action."', no port".json_encode($devices[$devI])."\n";
				continue;
			}
			
			echo $action.": ".$devices[$devI]["port"]." (".$devices[$devI]["serial"].")\n";
			switch ($action) {
				case "open-url":
					devOpenURL($deviceFromAction["serial"], $deviceFromAction["path"]);
					break;
					
				case "new-device":
					$devices[$devI]["stateFlags"]["usb-tethered"] = 1;
					$devices[$devI]["tetheredTo"] = CHARGING_STATION.'.'.$devices[$devI]["port"];
					break;
				
				case "disconnect":
					if (in_array($devices[$devI]["serial"], $notDisconnected)) {
						echo "\tFalse disconnect\n";
						continue 2;
					}
					$devices[$devI]["stateFlags"]["usb-tethered"] = 0;
					$devices[$devI]["tetheredTo"] = "NULL";
					if (isset($devices[$devI]["battery"]["USB powered"])) $devices[$devI]["battery"]["USB powered"] = false;
					break;
			}
			
		}
	}
	fclose($fp);
	file_put_contents('data/actions', implode("\n", $newActions));
}

foreach ($devices as $device) {
	if (!isset($device["serial"])) continue;
	$stateFile = 'dev/state/'.$device["serial"];
	$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : array();
	$updated = array();
	$updatedDesc = array();
	
	foreach ($device as $n=>$v) {
		if (isset($state[$n]) && is_array($state[$n])) {
			foreach ($state[$n] as $sn=>$sv) {
				if (!isset($v[$sn])) $v[$sn] = $sv;				
			}
		}
		
		if (!isset($state[$n]) || stateDiff($state[$n], $v)) {
			
			$updatedDesc[] =
				"\t`".$n."` was ".(isset($state[$n]) ? json_encode($state[$n]) : "undefined")."\n".
				"\t".str_repeat(" ",strlen($n))."is now ".json_encode($v);
			$updated[$n] = $v;
			$state[$n] = $v;
		}
	}
	
	if (count($updated)) {
		$updated["serial"] = $device["serial"];
		echo "UPDATED ".$device["serial"].": \n".implode("\n", $updatedDesc)."\n";
		$updated = parseDeviceData($updated);
		if ($res = ppReq('PUT', 'device', false, $updated)) {
			if (isset($res["res"]["commands"]) && $device["tetheredTo"] != "NULL") {
				foreach ($res["res"]["commands"] as $command) {
					array_splice($command, 1, 0, array($device["serial"]));
					//print_r($command);
					call_user_func_array('deviceCommand',$command);
				}
			}
			//echo json_encode($res)."\n";
		}
		file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
	}
}

//dbg($devices); 

function deviceCommand() {
	$args = func_get_args();
	switch (array_shift($args)) {
		case "view":
			call_user_func_array('devOpenURL', $args);
			break;
	}
}

function stateDiff($v1, $v2) {
	return normVar($v1) != normVar($v2);
}

function normVar($v) {
	if (in_array(gettype($v), array("integer", "double", "string"))) return "".$v;
	return json_encode($v);
}


function installAPKInDir($serial, $dir) {
	if ($dp = opendir($dir)) {
		while (($file = readdir($dp)) !== false) {
			$f = explode('.', $file);
			if (array_pop($f) == 'apk') {
				exec('adb -s '.escapeshellarg($serial).' install '.escapeshellarg($dir.'/'.$file));
				/*if (is_file($dir.'/README.txt')) {
					devOpenURL(escapeshellarg($serial), $dir.'/README.txt');
				}*/
				break;
			}
		}
		closedir($dp);
	}
}

function devScreenshot($serial, $outfile) {
	return adbCommand('screencap -p | perl -pe \'s/\x0D\x0A/\x0A/g\' > '.escapeshellarg($outfile));
}

function devAddShortcut($serial) {
	//TODO: doesn't work
	return adbCommand(
		$serial,
		'am broadcast '.
		'-a com.android.launcher.action.INSTALL_SHORTCUT '.
		'--es Intent.EXTRA_SHORTCUT_NAME "<shortcut-name>" '.
		'--esn Intent.EXTRA_SHORTCUT_ICON_RESOURCE '.
		'<package-name>/.activity'
	);
}

function devSetProp($serial, $n, $v) {
	return adbCommand(
		$serial,
		'setprop '.escapeshellarg($n).' '.escapeshellarg($v)
	);
}
		
	
function adbCommand($serial, $cmd) {
	$cmd = 'adb -s '.escapeshellarg($serial).' shell '.$cmd;
	
	devlog($serial, "ADB COMMAND: ".$cmd);

	exec($cmd." 2>&1", $out, $res);
	
	devlog($serial, "\t".implode("\n\t",$out));
	
	return $out;
}

function devOpenURL($serial, $path, $noRetry = false, $intent = '-a android.intent.action.VIEW') {
	$url = $path;
	/*$pwd = $_SERVER["PWD"];
	if ($pwd == substr($path, 0, strlen($pwd))) {
		$path = substr($path, strlen($pwd));
	}
	$url = DEV_CHECK_SERVER_ADDR.'/'.$path;*/
	
	$cmd = 'adb -s '.escapeshellarg($serial).' shell am start '.$intent.' -d '.($noRetry ? $url : escapeshellarg(urlencode($url)));
	devlog($serial, "ADB COMMAND: ".$cmd);

	exec($cmd." 2>&1", $out, $res);
	
	devlog($serial, "\t".implode("\n\t",$out));
	
	foreach ($out as $line) {
		if (stripos($line, 'unable to resolve intent') !== false) {
			if (!$noRetry && is_file('data/locs')) {
				$locs = explode("\n", trim(file_get_contents('data/locs')));
				$li = is_file('data/tmploci') ? (int)file_get_contents('data/tmploci') : 0;
				file_put_contents('data/tmploc/'.dechex($li), $path);			
				
				$res = devOpenURL($serial, 'http://'.$locs[0].'/device-reg/?tl='.dechex($li), true);
				$li ++;
				if ($li > 255) $li = 0;
				file_put_contents('data/tmploci', $li);
			}
			return;
		}
		if (stripos($line, 'device descriptor read/64, error') !== false) {
			ppReq('PUT', 'device', false, array(
				'station-request'=>array(
					'id'=>CHARGING_STATION,
					'action'=>'reboot now',
					'reason'=>'USB hub issues'
				)
			));
		}
		if (substr($line, 0, 6) == "error:") {
			$unauthorized = strpos($line, "device unauthorized") !== false;
			$data = array(
				"serialNum"=>$serial,
				"message"=>array(
					"type"=>"error",
					"text"=>substr($line, 6),
				)
			);
			
			$notFound = strpos($data["message"]["text"], "not found") !== false;
			if ($notFound) {
				
				devlog($serial, $serial." not found, waiting.");
				continue;
			}
			
			if ($unauthorized) {
				$data["stateFlags"]["requires-auth"] = 1;
			}
			
			//ppReq('PUT', 'device', false, $data);
			
			if ($unauthorized || $notFound) {
				$vars = array(
					"action"=>"open-url",
					"serial"=>$serial,
					"path"=>$path				
				);
				devlog($serial, "Queueing open URL retry ".json_encode($vars));
				file_put_contents('data/actions', json_encode($vars)."\n", FILE_APPEND);
			}
		}
	}
	
}

function devlog($serial, $txt) {
	echo $txt."\n";
	file_put_contents('dev/log/'.preg_replace('/[^\w\d+]/','', $serial), $txt."\n", FILE_APPEND);
	file_put_contents('log.txt', $txt."\n", FILE_APPEND);
}

function getSys($c, $matchers = false) {
	exec($c, $out, $res);
	if ($res !== 0) return false;
	if ($matchers !== false) {
		$rv = array();
		foreach ($out as $line) {
			foreach ($matchers as $matcher) {
				if (preg_match($matcher[0], $line, $m)) {
					if (!isset($matcher[1])) {
						$rv[] = isset($m[1]) ? $m[1] : 1;
					} elseif ($matcher[1] === true) {
						$rv[$m[1]] = $m[2];
					} elseif (is_array($matcher[1])) {
						$o = array();
						for ($i = 0; $i < count($matcher[1]); $i ++) {
							$o[$matcher[1][$i]] = $m[$i + 1];
						}
						$rv[] = $o;
					} else {
						$o = array();
						for ($i = 1; $i < count($matcher); $i ++) {
							$o[$matcher[$i]] = $m[$i];
						}
						$rv[] = $o;
					}
				}
			}
		}
		return $rv;
	}
	return $out;
}
