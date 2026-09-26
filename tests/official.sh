#!/usr/bin/env bash
# Run official Lua 5.4.9 test files through bin/lua, one at a time, from
# reference/lua-5.4.9-tests (as all.lua would), with
#   -e"_U=true _soft=true _port=true _nomsg=true"
# Prints PASS/FAIL per file (plus the first error line on failure) and
# exits 1 if any file fails.
#
#   tests/official.sh [name...]     names with or without ".lua"
#                                   (default: every top-level *.lua)
#
# Environment: OFFICIAL_TIMEOUT (seconds per file, default 300);
# OFFICIAL_PHP_OPTIONS (php options before bin/lua, e.g.
# "-d auto_prepend_file=$PWD/tests/unit/tiny_segments.php" to run every
# function as segments of one instruction).

set -u
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tests_directory="$repo_root/reference/lua-5.4.9-tests"
timeout_seconds="${OFFICIAL_TIMEOUT:-300}"

if [ "$#" -eq 0 ]; then
    set --
    for file in "$tests_directory"/*.lua; do
        set -- "$@" "$(basename "$file")"
    done
fi

failures=0
for name in "$@"; do
    name="${name%.lua}.lua"
    output_file="$(mktemp)"
    start_milliseconds=$(( $(date +%s%N) / 1000000 ))
    # (OFFICIAL_PHP_OPTIONS unquoted: split into its words)
    (cd "$tests_directory" && timeout "$timeout_seconds" php ${OFFICIAL_PHP_OPTIONS:-} "$repo_root/bin/lua" \
        -e"_U=true _soft=true _port=true _nomsg=true" "$name") >"$output_file" 2>&1
    status=$?
    elapsed_milliseconds=$(( $(date +%s%N) / 1000000 - start_milliseconds ))
    elapsed="$((elapsed_milliseconds / 1000)).$(( (elapsed_milliseconds % 1000) / 100 ))"
    if [ "$status" -eq 0 ]; then
        echo "PASS $name (${elapsed}s)"
    else
        failures=$((failures + 1))
        if [ "$status" -eq 124 ]; then
            first_error="timeout after ${timeout_seconds}s"
        else
            first_error="$(grep -m1 -E "^(lua|php|PHP|.*bin/lua):|Fatal|Error" "$output_file" || tail -n 1 "$output_file")"
        fi
        echo "FAIL $name (${elapsed}s): $first_error"
    fi
    rm -f "$output_file"
done

if [ "$failures" -ne 0 ]; then
    echo "$failures file(s) failed"
    exit 1
fi
