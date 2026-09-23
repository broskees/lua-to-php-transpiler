#!/usr/bin/env bash
# Diff `bin/luac -l -l -p FILE` against `luac5.4 -l -l -p FILE` for each FILE
# (default: every top-level *.lua in reference/lua-5.4.9-tests), ignoring
# 0x... addresses. Both run from FILE's directory on its basename, so the
# chunk names match. Prints PASS/FAIL per file; exits 1 if any file fails.
#
#   tests/bytecode.sh [file...]

set -u
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "$#" -eq 0 ]; then
    set -- "$repo_root"/reference/lua-5.4.9-tests/*.lua
fi

normalize_addresses() {
    sed -E 's/0x[0-9a-f]+/0x?/g'
}

failures=0
for file in "$@"; do
    file_directory="$(dirname "$file")"
    file_name="$(basename "$file")"
    expected="$(cd "$file_directory" && luac5.4 -l -l -p "$file_name" 2>&1 | normalize_addresses)"
    actual="$(cd "$file_directory" && php "$repo_root/bin/luac" -l -l -p "$file_name" 2>&1 | normalize_addresses)"
    if [ "$expected" == "$actual" ]; then
        echo "PASS $file"
    else
        echo "FAIL $file"
        diff <(printf '%s\n' "$expected") <(printf '%s\n' "$actual") | head -n 20
        failures=$((failures + 1))
    fi
done

if [ "$failures" -ne 0 ]; then
    echo "$failures file(s) failed"
    exit 1
fi
