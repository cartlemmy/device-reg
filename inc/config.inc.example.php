<?php

header('Access-Control-Allow-Origin: http://paliportal.com', false);

define('DEV_CHECK_SERVER_ADDR', 'https://paliportal.com');
define('CHARGING_STATION', 'AA.1');
define('PUBLIC_KEY', 'ssh-rsa .... josh@josh-laptop'); //Public SSH key for termux SSH

exec('ifconfig', $out);

$out = implode("\n", $out);
if (preg_match_all('/inet\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $out, $m)) {
	foreach ($m[1] as $ip) {
		if ($ip != "127.0.0.1") {
			define('WWW_HOST', $ip);
			break;
		}
	}
}

define('WWW', 'http://'.WWW_HOST.'/device-reg/');
