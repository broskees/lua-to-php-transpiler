#!/usr/bin/env bash
# Runs one command that runs PHP or Lua the way this shared machine needs it:
#
#   bench/score/capped.sh <command> [arguments...]
#
# in a systemd user scope capped at 3 GB of memory and no swap, with an 8 MB C
# stack (ulimit -s 8192, the usual default), killed after CAPPED_TIMEOUT
# seconds (default 600). For long or big runs use bench/score/heavy.sh.
#
# The exit status is the command's (137: killed by the cap or the timeout).

set -u

if [ "$#" -eq 0 ]; then
    echo "usage: bench/score/capped.sh <command> [arguments...]" >&2
    exit 2
fi

ulimit -s 8192
exec timeout -s KILL "${CAPPED_TIMEOUT:-600}" \
    systemd-run --user --scope --quiet -p MemoryMax=3G -p MemorySwapMax=0 "$@"
