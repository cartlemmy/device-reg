<?php

return array(
	"battery"=>array(
		"status"=>array(
			1=>"Unknown",
			2=>"Charging",
			3=>"Discharging",
			4=>"Not charging",
			5=>"Full"
		),
		"health"=>array(
			1=>"Unknown",   
			2=>"Good",
			3=>"Overheat",
			4=>"Dead",
			5=>"Over voltage",
			6=>"Unspecified failure",
			7=>"Cold"
		)
	)
);

function parseParam($v, $conv) {
	$v = preg_replace('/[^a-z0-9]+/', '',  strtolower($v));
	foreach ($conv as $m) {
		if (preg_replace('/[^a-z0-9]+/', '',  strtolower($m)) === $v) return $m;
	}
	return null;
}

function dbg($o) {
	echo json_encode($o, JSON_PRETTY_PRINT)."\n";
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

