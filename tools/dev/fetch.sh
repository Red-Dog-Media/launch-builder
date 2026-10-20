#!/bin/bash
# fetch.sh <path>...: status, redirect and <title> of each path.
for p in "$@"; do
	code=$(curl -s -o /tmp/claude-0/-home-user-launch-builder/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/t2/page.html -w "%{http_code} %{redirect_url}" --max-time 60 "http://127.0.0.1:8899$p")
	echo "$code $p $(grep -o '<title>[^<]*' /tmp/claude-0/-home-user-launch-builder/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/t2/page.html | head -1)"
done
