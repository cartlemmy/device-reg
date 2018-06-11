#!/bin/bash

if ! [ -x "$(command -v php)" ]; then
  echo 'Requires php' >&2
  exit 1
fi

if ! [ -x "$(command -v curl)" ]; then
  echo 'Requires curl' >&2
  exit 1
fi

if ! curl --fail "http://127.0.0.1/device-reg/?start=1"; then
  echo 'Couldn\t reach http://127.0.0.1/device-reg/?start=1' >&2
  exit 1
fi

if [ ! -d data ]; then
	mkdir -p data/tmploc
fi

if [ ! -d data/new-device ]; then
	mkdir -p data/new-device
fi

if [ ! -d dev ]; then
	mkdir dev
	mkdir dev/log
	mkdir dev/state
fi

if [ -f data/required-apks-hash ]; then
	mv data/required-apks-hash data/required-apks-hash-prev
fi

wget -O data/required-apks-hash https://paliportal.com/apks?check=1

if [ -f data/required-apks-hash-prev ]; then
	if cmp -s data/required-apks-hash data/required-apks-hash-prev; then
		echo "required-apks has not changed"
	else
		./update-required-apks.sh
	fi
else
	./update-required-apks.sh
fi

if [ ! -f ./inc/config.inc.php ]; then
	echo "Config file missing (./inc/config.inc.php)"
	echo "Example config: ./inc/config.inc.example.php"
	exit
fi

./proc.php

if ps aux | grep "[u]sb-check-loop.sh"; then
	echo "PaliDevice Manager is already running"
	exit
else
	echo "Starting Pali Device Manager"
	echo "" > ./log.txt
	touch first-run
	nohup ./usb-check-loop.sh >/dev/null & echo $! > ./usb-check-loop.pid
fi
