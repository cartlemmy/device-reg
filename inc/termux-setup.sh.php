#!/data/data/com.termux/files/usr/bin/bash

touch '/storage/sdcard0/Documents/termux-setup-started'
# Updating termux
pkg update

# Installing needed packages
pkg install -y openssh termux-api curl

termux-dialog -t "Pali Device ID" > "/storage/sdcard0/Documents/pali-device-id"

# Starting sshd
sshd

# Adding the public key
echo "<?=PUBLIC_KEY;?>" >> ./.ssh/authorized_keys
# fetching the update script, and making it executable
if [ -f "./send-upd.sh" ]; then
	rm -f "./send-upd.sh"
fi
wget "<?=WWW;?>dl/send-upd.sh"
chmod +x ./send-upd.sh

# fetching the update script runner, and making it executable
if [ -f "./start.sh" ]; then
	rm -f "./start.sh"
fi
wget "<?=WWW;?>dl/start.sh"
chmod +x ./start.sh

# starting the update script runner
./start.sh &

touch '/storage/sdcard0/Documents/termux-setup-complete'
