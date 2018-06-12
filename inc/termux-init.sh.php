#!/data/data/com.termux/files/usr/bin/bash

# Updating termux
pkg update

# Installing needed packages
pkg install openssh termux-api curl

# Starting sshd and crond
sshd
crond

# Adding the public key
echo "<?=PUBLIC_KEY;?>" >> ./.ssh/authorized_keys

# fetching the update script, and making it executable
wget "<?=WWW;?>dl/send-upd.sh"
chmod +x ./send-upd.sh

# Updating crontab to send regular updates
TMP_FILE=termux-tmp

crontab -l > "$TMP_FILE"

echo "/30 * * * * cd /data/data/com/termux/files/usr/files/home; ./send-upd.sh" >> "$TMP_FILE"

crontab "$TMP_FILE"
rm "$TMP_FILE"
