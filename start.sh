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

if pidof -x "usb-check-loop.sh" >/dev/null; then
	exit
else
	cd /var/www/html; nohup ./usb-check-loop.sh >/dev/null 2>&1 &
fi
