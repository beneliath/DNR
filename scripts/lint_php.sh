#!/bin/sh
set -eu

find src scripts tests -type f -name '*.php' -print0 \
    | xargs -0 -n1 php -l
