<?php

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
	if (!is_string($txt)) $txt = json_encode($txt, JSON_PRETTY_PRINT);
	file_put_contents(realpath(__DIR__.'/..').'/log.txt', $txt."\n", FILE_APPEND);;
}

function verbose($txt) {
}


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
		
	
function adbCommand() {
	$args = func_get_args();
	$serial = array_shift($args);
	$cmd = array();
	foreach ($args as $arg) {
		$cmd[] = 'adb -s '.escapeshellarg($serial).' shell '.$arg;
	}
	$cmd = implode(' && ', $cmd);
	
	devlog($serial, "ADB COMMAND: ".$cmd);
	dbg($serial." ADB COMMAND: ".$cmd);
	
	exec($cmd." 2>&1", $out, $res);
	
	$outStr = implode("\n", $out);
	if (strpos($outStr, 'Try \'adb kill-server\'') !== false) {
		setGlobal('adb-restart');
		return false;
	}
	/*if ($unauthorized) {
		$data["stateFlags"]["requires-auth"] = 1;
	}*/	
			
	devlog($serial, "\t".implode("\n\t",$out));
	
	return $out;
}

function canSee($serial, $host) {
	if (($wan = getSys(
		'adb -s '.escapeshellarg($serial).' shell ping -c 1 '.escapeshellarg($host),
		array(array('/1 received/'))
	)) !== false) {
		return count($wan) == 0 || trim($wan[0]) == "" ? false : true;
	}
	return null;
}

function sendInputFromFile($serial, $file, $intent = 'com.termux/com.termux.app.TermuxActivity') {
	
	if (substr($file, -4) === '.php') {
		ob_start();
		require($file);
		$file = str_replace(
			array('inc/', '.php'),
			array('dl/', ''),
			$file
		);
		file_put_contents($file, ob_get_clean());
	}
	dbg('PHP file parsed to: '.$file);

	return $file;
	
	if (is_file($file) && ($fp = fopen($file, 'r'))) {
		adbCommand($serial, 'input keyevent 82', 'input touchscreen swipe 300 1024 300 10 1000', 'am start -n '.$intent);
		sleep(4);
		$i = 0;
		while (!feof($fp)) {
			$line = fgets($fp);
			if (substr($line, 0, 1) == '#') { 
				if ($i === 0) { $i++; continue; }
				if (substr($line, 0, 5) == '#key:') {
					adbCommand($serial, 'input keyevent '.trim(substr($line,5)));
					usleep(2000000);
					continue;
				}
			}
			
			if (trim($line)) {
				adbCommand($serial, 'input text '.escapeADBInputText($line));	
				usleep(200000);
			}
			adbCommand($serial, 'input keyevent 66');	
						
			$i++;			
		}
		//adbCommand($serial, );
		fclose($fp);
	}
}

function escapeADBInputText($txt) {
	$txt = trim($txt);
	$txt = str_replace(
		array('#', '(',  ')',  '<',  '>',  '|',  ';',  '&',  '*',  '\\',  '~',  '"',  '\'', ' '),
		array('\\#','\\(',  '\\)',  '\\<',  '\\>',  '\\|',  '\\;',  '\\&',  '\\*',  '\\\\',  '\\~',  '\\"',  '\\\'','%s'),
		$txt
	);
		
	return '"'.$txt.'"';
}

function devOpenURL($serial, $path, $noRetry = false, $intent = '-a android.intent.action.VIEW') {
	$url = $path;
	
	if (preg_match('/^(http|https)\:\/\/([A-Za-z0-9\.\-]+)\//', $path, $host)) {
		if (!canSee($serial, $host[2])) {
			devlog($serial, 'Cannot connect to '.$host[2]);
			return false;
		}
	}
	
	/*$pwd = $_SERVER["PWD"];
	if ($pwd == substr($path, 0, strlen($pwd))) {
		$path = substr($path, strlen($pwd));
	}
	$url = DEV_CHECK_SERVER_ADDR.'/'.$path;*/
	
	$cmd = 'am start '.$intent.' -d '.($noRetry ? $url : escapeshellarg(urlencode($url)));
	
	//exec($cmd." 2>&1", $out, $res);
	
	$out = adbCommand($serial, $cmd);
	
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
		if (strpos($line, "error:") !== false) {			
			$notFound = strpos($data["message"]["text"], "not found") !== false;
			if ($notFound) {
				devlog($serial, $serial." not found, waiting.");
				continue;
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

function setGlobal($n, $v = true) {
	$path = realpath(__DIR__.'/..').'/data/'.$n;
	if ($v === true) {
		touch($path);
	} else {
		file_put_contents($path, json_encode($v));
	}
}

function getGlobal($n, $def = null) {
	$path = realpath(__DIR__.'/..').'/data/'.$n;
	if (is_file($path)) {
		$raw = file_get_contents($path);
		if ($raw === '') return true;
		return json_decode($raw, true);
	}
	return $def;
}

function clearGlobal($n) {
	$path = realpath(__DIR__.'/..').'/data/'.$n;
	if (is_file($path)) unlink($path);
}

function devlog($serial, $txt) {
	echo $txt."\n";
	file_put_contents('dev/log/'.preg_replace('/[^\w\d+]/','', $serial), $txt."\n", FILE_APPEND);
	file_put_contents('log.txt', $txt."\n", FILE_APPEND);
}

function getSys($c, $matchers = false) {
	dbg('getSys: '.$c);
	exec($c.' 2>&1', $out, $res);
	if ($res !== 0) {
		dbg($out);
		return false;
	}

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

function parseParam($v, $conv) {
	$v = preg_replace('/[^a-z0-9]+/', '',  strtolower($v));
	foreach ($conv as $m) {
		if (preg_replace('/[^a-z0-9]+/', '',  strtolower($m)) === $v) return $m;
	}
	return null;
}

function paramDecode($p) {
	$p = trim($p);
	if ($p === "false") return false;
	if (($dec = json_decode($p, true)) !== false) return $dec;
	return $p;
}

function ppReq($method, $endpoint, $params = false, $data = false) {

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Accept: application/json',
		'Accept-Charset: utf-8',
		'X-Yp-Key: 4.a1vZL8Gp5J6A9VML7A99oz7fzTcBcLdj'
	));
	
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);           
	if (is_array($data)) $data = json_encode($data);                                                          
	if (trim($data)) curl_setopt($ch, CURLOPT_POSTFIELDS, $data); 
	
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	$url = "https://paliportal.com/v1/json/".$endpoint."/".($params === false ? '' : str_replace(' ','%20',$params));

	echo $method." ".$url."\n";
	if (trim($data)) echo "\t".$data."\n";
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_REFERER, 'YP Device Reg');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	//curl_setopt($ch, CURLOPT_VERBOSE, 1);
	//curl_setopt($ch, CURLOPT_HEADER, 1);
	
	$raw = curl_exec($ch);
	
	//$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	//$headers = API::parseHeaders(substr($raw, 0, $headerSize));
	//$raw = substr($raw, $headerSize);

	curl_close($ch);
	
	$json = false;

	if ($json = json_decode($raw, true)) {
		return $json;
	}
	//if ($json) var_dump($json);
	
	echo $raw;

	return false;
}

function parseDeviceData($data) {
	$rv = array();
	if (isset($data["serial"])) $rv["ser"] = $data["serial"];
	if (isset($data["tetheredTo"])) $rv["tetheredTo"] = $data["tetheredTo"];
	if (isset($data["battery"]["level"])) $rv["batt"] = (int)$data["battery"]["level"];
	if (isset($data["mac"])) $rv["mac"] = $data["mac"];
	if (isset($data["stateFlags"])) $rv["stateFlags"] = $data["stateFlags"];
	if (isset($data["lanIP"])) $rv["lanIP"] = $data["lanIP"];

	return $rv;
}

