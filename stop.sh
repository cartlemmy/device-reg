#!/bin/bash

if [ -f usb-check-loop.pid ]; then
	if kill $(cat usb-check-loop.pid); then
		echo "Pali Device Manager successfully stopped"
		rm -f usb-check-loop.pid
		exit 0
	fi
fi

echo "Pali Device Manager is already stopped"
