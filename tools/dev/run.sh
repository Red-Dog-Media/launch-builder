#!/bin/bash
# run.sh <script> [args...]: run a test script on the v2 test database.
cd /tmp/claude-0/-home-user/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad && LBT_DB=${LBT_DB:-v2.sqlite} php bin/run.php site "$@"
