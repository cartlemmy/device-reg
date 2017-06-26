<?php

echo '<pre>';
require('config.inc.php');

$locs = array();

exec('ifconfig', $out);

$out = implode("\n", $out);
if (preg_match_all('/inet\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $out, $m)) {
	foreach ($m[1] as $ip) {
		if ($ip != "127.0.0.1") $locs[] = $ip;
	}
}

if (!in_array($_SERVER["SERVER_NAME"], $locs)) $locs[] = $_SERVER["SERVER_NAME"];

$locs = implode("\n",$locs);

$oldLocs = is_file('data/locs') ? file_get_contents('data/locs') : '';

if ($locs != $oldLocs) {
	
	file_put_contents('./data/locs', $locs);
	$cmd = 'curl '.escapeshellarg(DEV_CHECK_SERVER_ADDR.'/dev?'.http_build_query(array(
		"station"=>CHARGING_STATION,
		"locs"=>$locs
	)));
	echo $cmd;
	system($cmd);

}
