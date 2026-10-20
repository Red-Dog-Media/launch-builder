#!/bin/bash
# serve.sh <db file>: (re)start the dev server on 127.0.0.1:8899 with that database.
for pid in $(ps aux | grep '[p]hp -S 127.0.0.1:8899' | awk '{print $2}'); do kill $pid; done
sleep 0.5
cd /tmp/claude-0/-home-user/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/site && (LBT_DB=$1 nohup php -S 127.0.0.1:8899 -t . ../bin/router.php > /tmp/claude-0/-home-user/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/server-v2.log 2>&1 &)
sleep 1.5
