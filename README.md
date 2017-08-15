# roughEtcBak.php
A little PHP script to facilitate selectively backing-up and restoring files. Maybe useful for /etc files.

See comments in source code for usage.

## Usage example:
At the server of which files are to be backed-up:

```
mkdir ~/bak
cd ~/bak
vi roughEtcBak.txt # See [sample]()
#... and also put roughEtcBak.php in this directory ...
sudo php roughEtcBak.php
# See appendix for enabling passwordless SSH/rsync.
sudo rsync -a --copy-unsafe-links --delete -e ssh --rsync-path="sudo rsync" /home/serverAdmin/bak/ serverAdmin@standbyServer:~/bakFromMaster
```

You can put the rsync command into root's crontab so that contents of `bak` constantly get sync'ed.

At the server where backup files are to be restored:

```
cd ~/bakFromMaster
sudo php roughEtcBak.php -r
```

### Appendix: Enable passwordless SSH/rsync for root (in Ubuntu)

```
sudo ssh-keygen
mkdir ~/.ssh
sudo ssh-copy-id -i /root/.ssh/id_rsa.pub remoteUser@remoteHostToSshInto
```

And [let the remote-side rsync run as root](https://askubuntu.com/questions/719439) so that rsync -a could really work.