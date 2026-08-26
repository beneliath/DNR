#!/bin/bash
set -eu

exec 3<>/dev/tcp/127.0.0.1/1143
IFS= read -r -t 3 banner <&3
exec 3>&-

[[ "$banner" == *" OK "* ]]
