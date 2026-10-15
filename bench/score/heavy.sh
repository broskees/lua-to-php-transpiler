#!/usr/bin/env bash
# Runs one long or big command (the scorer, php tests/run.php without a filter,
# all.lua, anything over a minute or 500 MB) the way this shared machine needs
# it:
#
#   bench/score/heavy.sh <command> [arguments...]
#
# - alone: it holds /var/tmp/luaphp-heavy.lock (flock) while it runs, and
#   waits while another heavy run holds it, so heavy runs never overlap (the
#   scorer's timings stay clean, and memory stays bounded);
# - once MemAvailable is at least 6 GB (it waits up to an hour);
# - through bench/score/capped.sh (3 GB, 8 MB C stack), killed after
#   HEAVY_TIMEOUT seconds (default 5400).
#
# The exit status is the command's (137: killed by the cap or the timeout).

set -u

lock=/var/tmp/luaphp-heavy.lock
lock_busy_status=213
directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "${1:-}" = "--holding-lock" ]; then
    shift
    needed_kilobytes=$((6 * 1024 * 1024))
    waited_seconds=0
    while true; do
        available_kilobytes=$(awk '/^MemAvailable:/ { print $2 }' /proc/meminfo)
        if [ "$available_kilobytes" -ge "$needed_kilobytes" ]; then
            break
        fi
        if [ "$waited_seconds" -ge 3600 ]; then
            echo "heavy.sh: MemAvailable stayed below 6 GB for an hour; not starting $1" >&2
            exit 1
        fi
        if [ "$waited_seconds" -eq 0 ]; then
            echo "heavy.sh: waiting for 6 GB of MemAvailable (now $((available_kilobytes / 1024)) MB)" >&2
        fi
        sleep 30
        waited_seconds=$((waited_seconds + 30))
    done
    LUAPHP_HEAVY_LOCK_HELD=1 CAPPED_TIMEOUT="${HEAVY_TIMEOUT:-5400}" exec "$directory/capped.sh" "$@"
fi

# inside a heavy run already (bench/score/score.sh through heavy.sh): waiting
# for the lock would wait for ourselves
if [ "${LUAPHP_HEAVY_LOCK_HELD:-}" = "1" ]; then
    exec "$@"
fi

if [ "$#" -eq 0 ]; then
    echo "usage: bench/score/heavy.sh <command> [arguments...]" >&2
    exit 2
fi

: >>"$lock"
# -o: the lock's file descriptor is not passed on, so a process that outlives
# its scope cannot keep the lock
flock -n -o -E "$lock_busy_status" "$lock" "$directory/heavy.sh" --holding-lock "$@"
status=$?
if [ "$status" -ne "$lock_busy_status" ]; then
    exit "$status"
fi
echo "heavy.sh: another heavy run holds $lock; waiting ($(date +%H:%M:%S))" >&2
exec flock -o "$lock" "$directory/heavy.sh" --holding-lock "$@"
