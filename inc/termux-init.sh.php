#!/data/data/com.termux/files/usr/bin/bash
if [ -f "./termux-setup.sh" ]; then rm -f ./termux-setup.sh; fi
wget "<?=WWW;?>dl/termux-setup.sh"
chmod +x ./termux-setup.sh
./termux-setup.sh
