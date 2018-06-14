#!/usr/bin/php
<?php

require('inc/config.inc.php');
$androidData = require('inc/android-data.php');

require_once('inc/common.php');
require('inc/proc.php');

exec("pgrep dev-check.php", $pids);
if (count(explode("\n",trim(implode("\n",$pids)))) >= 2) {
	verbose("dev-check.php already running");
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

dbg($devices);

if ($newDevices = getList('new-device')) {
	foreach ($newDevices as $newDev) {
		foreach ($devices as $device) {
			if ($newDev["serial"] == $device["serial"]) {
				unlink('data/new-device/'.$device["serial"]);
				continue 2;
			}
		}
		echo "ADB has not yet recognized the new device:\n\t".$device["serial"]."\n\n";
	}
}

$overview = array(array("Device"));
$notDisconnected = array();
foreach ($devices as $devNum=>$device) {
	$overviewFlags = array();
	$serArg = escapeshellarg($device["serial"]);
	
	$stateFile = 'dev/state/'.$device["serial"];

	$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : array();
	
	if ($state) {
		foreach ($state as $n=>$v) {
			if (!isset($device[$n])) $device[$n] = $v;
		}
	}
	
	if (!isset($device["regState"])) {
		$device["regState"] = "new";
		dbg('New device found '.json_encode($device));
		file_put_contents('data/actions', json_encode(array(
			"action"=>"new-device",
			"serial"=>$device["serial"],
			"port"=>$device["port"]
		))."\n", FILE_APPEND);
		$overviewFlags = array("NEW");
	}
	
	
	if (!isset($device["tetheredTo"]) || !$device["tetheredTo"] || $device["tetheredTo"] == 'NULL') {
		dbg('Connected: '.$device["serial"]);
		file_put_contents('data/actions', json_encode(array(
			"action"=>"connected", 
			"serial"=>$device["serial"],
			"port"=>$device["port"]
		))."\n", FILE_APPEND);
	}
	
	$notDisconnected[] = $device["serial"];
	$device["stateFlags"] = array("usb-tethered"=>1);
	$device["tetheredTo"] = CHARGING_STATION.'.'.$device["port"];
	
	if (($propsIn = getSys('adb -s '.$serArg.' shell getprop')) !== false) {
		$props = array();
		foreach ($propsIn as $prop) {
			if (preg_match('/\[([\w\d\.]+)\]\:\s*\[([^\]]+)\]/', $prop, $m)) {
				$path = explode('.', $m[1]);
				$branch = &$props;
				while ($n = array_shift($path)) {
					if (count($path)) {
						if (!isset($branch[$n])) $branch[$n] = array();
						$branch = &$branch[$n];
					} else {
						$branch[$n] = $m[2];
					}
					
				}
				unset($branch);
			}
		}
		file_put_contents(
			'dev/state/'.$device["serial"].'.props.json',
			json_encode($props, JSON_PRETTY_PRINT)
		);
	}
	
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
		
		$overviewFlags[] = 'BATT:'.$device["battery"]["level"].'%,'.$device["battery"]["status"].",".$device["battery"]["health"];
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
	$installed = array();
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
		if (in_array("com.termux", $packages) && in_array("com.termux.api", $packages)) {
			if (
				isset($device["termux-init"]) && 
				(!isset($device["termux-init-request"]) || time() > $device["termux-init-request"] + 300)
			) {
				dbg('Initializing termux for '.$device["serial"]);
				
				
				if ($file = sendInputFromFile($device["serial"], 'inc/termux-init.sh.php')) {
					$device["termux-init-request"] = time();					
				}
			}
		}
		$showPackages = array(
			'com.android.vending'=>'Google Play Store',
			'com.termux'=>'Termux',
			'com.termux.api'=>'Termux API'			
		);
		
		foreach ($showPackages as $n=>$label) {
			if (in_array($n, $packages)) $installed[] = $label;
		}
		
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
	$locs = explode("\n", trim(file_get_contents('data/locs')));
	
	$device["stateFlags"]["wan-connected"] = canSee( $device["serial"], 'paliportal.com') ? 1 : 0;
	
	if (!isset($device["lastSeenByController"]) || time() > $device["lastSeenByController"]	+ 120) {
		$check = array();
		if (isset($device["connectedToController"])) {
			$check[] = $device["connectedToController"]; //check this one first
		}
		foreach ($locs as $loc) {
			if (!in_array($loc, $check)) $check[] = $loc;
		}
		$device["stateFlags"]["controller-connected"] = 0;
		$device["connectedToController"] = false;
		
		foreach ($check as $loc) {
			if (canSee( $device["serial"], $loc)) {
				$device["stateFlags"]["controller-connected"] = 1;
				$device["connectedToController"] = $loc;
				$device["lastSeenByController"] = time();
				break;
			}
		}
	}	
	
	if ($device["stateFlags"]["wan-connected"]) $overviewFlags[] = 'WAN';
	if ($device["stateFlags"]["controller-connected"]) $overviewFlags[] = 'CTC('.$device["connectedToController"].')';
	
	$devices[$devNum] = $device;
	
	$overview[] = array(
		$device["serial"],
		$device["regState"],
		implode(" ", $overviewFlags),
		count($installed) ? 'Installed: '.implode(", ", $installed) : ''
	);
}
unset($device);

ob_start();
//echo "== Attached Devices ==";
foreach ($overview as $i=>$l) {
	echo implode("   ", $l)."\n";
}
file_put_contents('dev/overview.txt', ob_get_clean());

$newActions = array();
if ($fp = @fopen('data/actions', 'r')) {
	while (!feof($fp)) {
		$line = fgets($fp);
		if ($deviceFromAction = json_decode($line, true)) {
			$action = $deviceFromAction["action"];
			if ($noneConnected && $action != 'disconnect') {
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
				dbg("\tTried Action '".$action."', no port".json_encode($devices[$devI]));
				continue;
			}
			
			verbose($action.": ".$devices[$devI]["port"]." (".$devices[$devI]["serial"].")");
			switch ($action) {
				case "open-url":
					devOpenURL($deviceFromAction["serial"], $deviceFromAction["path"]);
					break;
					
				case "new-device":
					break;
					
				case "connected":
					$devices[$devI]["stateFlags"]["usb-tethered"] = 1;
					$devices[$devI]["tetheredTo"] = CHARGING_STATION.'.'.$devices[$devI]["port"];
					break;
				
				case "disconnect":
					if (in_array($devices[$devI]["serial"], $notDisconnected)) {
						dbg("\tFalse disconnect");
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
		verbose("UPDATED ".$device["serial"].": \n".implode("\n", $updatedDesc));
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
