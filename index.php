<?php

require('config.inc.php');

if (isset($_GET["tl"])) {
	header('Location: '.file_get_contents('data/tmploc/'.$_GET["tl"]));
	exit();
}

$info = array();

$addr = $_SERVER["REMOTE_ADDR"];

exec('ping '.escapeshellarg($addr).' -c 1; arp -a', $out);

$mac = false;

foreach ($out as $line) {
	if (preg_match('/\('.preg_quote($addr).'\)\s+at\s+([\da-fA-F]{2}\:[\da-fA-F]{2}\:[\da-fA-F]{2}\:[\da-fA-F]{2}\:[\da-fA-F]{2}\:[\da-fA-F]{2})/', $line, $m)) {
		$mac = $m[1];
		break;
	}
}

$info["mac"] = $mac;
$info["ip"] = $addr;

unset($out);

$cmd = 'grep -ir '.escapeshellarg($mac).' dev/state 2>&1';

exec($cmd, $out);
$file = array_shift(explode(':  ', $out[0]));
if (is_file($file) && ($state = json_decode(file_get_contents($file),true))) {
	if (isset($state["serial"])) $info["serial"] = $state["serial"];
}

if (isset($_GET["redir"])) {
	header("Location: ".$_GET["redir"]."?".http_build_query($info));
	exit();
}

header('Content-Type: application/json');
echo json_encode($info, JSON_PRETTY_PRINT);
