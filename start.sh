#!/bin/bash

if [ ! -d data ]; then
	mkdir data
	mkdir data/tmploc
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
	echo "Pali Mevice Manager is already running"
	exit
else
	echo "Starting Pali Device Manager"
	nohup ./usb-check-loop.sh & echo $! > ./usb-check-loop.pid
fi
