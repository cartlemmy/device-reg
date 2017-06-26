#!/bin/bash

echo "updating required apks"
wget -O data/required-apks.zip https://paliportal.com/apks
unzip data/required-apks.zip -d /tmp
rsync -arv --delete /tmp/required-apks ./
rm -rf /tmp/required-apks
