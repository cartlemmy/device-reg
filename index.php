<?php

ini_set('display_errors',1);
ini_set('log_errors',0);

require('inc/config.inc.php');

if (isset($_GET["termux"])) {
	?><b>Copy-paste below to termux:</b>
	<code>
	rm -f termux-init.sh; wget <?=WWW;?>dl/termux-init.sh; chmod +x termux-init.sh; ./termux-init.sh
	</code><?php
	return;
}
if (isset($_GET["tl"])) {
	header('Location: '.file_get_contents('data/tmploc/'.$_GET["tl"]));
	exit();
}

if (isset($_GET["start"])) {
	if (!function_exists('curl_init')) {
		echo "The PHP Curl module is not installed.\n";
		exit(1);
	}
	echo "HTTP service is responding from ".$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"]."\n";
	exit(0);
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

$rawInput = file_get_contents("php://input");
//echo '!!!'.$rawInput."\n\n".substr($rawInput, 0, 10);
file_put_contents('debug.txt', $rawInput);
//$rawInput = file_get_contents('debug.txt');

if (substr($rawInput, 0, 10) === '== info ==') require('inc/parse-termux.php');

unset($out);

$cmd = 'grep -ir '.escapeshellarg($mac).' dev/state 2>&1';

exec($cmd, $out, $rv);
if ($rv === 0) {
	$file = explode(':  ', $out[0]);
	$file = array_shift($file);
	if (is_file($file) && ($state = json_decode(file_get_contents($file),true))) {
		if (isset($state["serial"])) $info["serial"] = $state["serial"];
	}
}

if (isset($_GET["redir"])) {
	header("Location: ".$_GET["redir"]."?".http_build_query($info));
	exit();
}

header('Content-Type: application/json');
echo json_encode($info, JSON_PRETTY_PRINT);
