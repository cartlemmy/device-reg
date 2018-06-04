<?php

$androidData = require('inc/android-data.php');

$parts = preg_split('/== ([A-Za-z0-9\-]+) ==\n/', $rawInput, null, PREG_SPLIT_DELIM_CAPTURE);

for ($i = 1; $i < count($parts); $i += 2) {
	$part = $parts[$i];
	$content = $parts[$i + 1];
	
	switch ($part) {
		case "battery-status":
		case "wifi-scaninfo":
		case "wifi-connectioninfo":
		case "location":
			$parsed = json_decode($content, true);
			break;
		
		default:
			continue 2;
	}
	
	switch ($part) {
		case "battery-status":
			$info["batt"] = $parsed["percentage"];
			
			if (!isset($info["battery"])) $info["battery"] = array();
			$info["battery"]["health"] = parseParam($parsed["health"], $androidData["battery"]["health"]);
			
			if (!isset($info["battery"])) $info["battery"] = array();
			$info["battery"]["status"] = parseParam($parsed["status"], $androidData["battery"]["status"]);
			break;
			
		case "wifi-connectioninfo":
			$info["mac"] = $parsed["mac_address"];		
			break;
		
		case "location":		  
			if (isset($parsed["latitude"])) $info["lat"] = $parsed["latitude"];
			if (isset($parsed["longitude"])) $info["lon"] = $parsed["longitude"];
			if (isset($parsed["altitude"])) $info["alt"] = $parsed["altitude"];
			if (isset($parsed["accuracy"])) $info["acc"] = $parsed["accuracy"];
			if (isset($parsed["bearing"])) $info["dir"] = $parsed["bearing"];
			break;
	}
}

if (isset($info["mac"]) && $info["mac"]) {
		if ($res = ppReq('PUT', 'device', false, $info)) {
			echo json_encode($res, JSON_PRETTY_PRINT)."\n";
		}
} else {
	echo '0';
}
exit();
