#!/usr/bin/env bash
# Compare bin/luac against luac5.4 for each FILE (default: every top-level
# *.lua in reference/lua-5.4.9-tests plus our corpus in tests/bytecode):
#   - `-l -l -p FILE` listings, ignoring 0x... addresses;
#   - `-o` binary dumps, with and without `-s`, byte for byte.
# Both run from FILE's directory on its basename, so the chunk names match.
# Prints PASS/FAIL per file; exits 1 if any file fails.
#
#   tests/bytecode.sh [file...]

set -u
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "$#" -eq 0 ]; then
    set -- "$repo_root"/reference/lua-5.4.9-tests/*.lua "$repo_root"/tests/bytecode/*.lua
fi

normalize_addresses() {
    sed -E 's/0x[0-9a-f]+/0x?/g'
}

dump_directory="$(mktemp -d)"
trap 'rm -rf "$dump_directory"' EXIT

failures=0
for file in "$@"; do
    file_directory="$(dirname "$file")"
    file_name="$(basename "$file")"
    problems=""

    expected="$(cd "$file_directory" && luac5.4 -l -l -p "$file_name" 2>&1 | normalize_addresses)"
    actual="$(cd "$file_directory" && php "$repo_root/bin/luac" -l -l -p "$file_name" 2>&1 | normalize_addresses)"
    if [ "$expected" != "$actual" ]; then
        problems="listing"
    fi

    for strip_option in "" "-s"; do
        (cd "$file_directory" && luac5.4 $strip_option -o "$dump_directory/expected.out" "$file_name" 2>/dev/null)
        (cd "$file_directory" && php "$repo_root/bin/luac" $strip_option -o "$dump_directory/actual.out" "$file_name" 2>/dev/null)
        if ! cmp -s "$dump_directory/expected.out" "$dump_directory/actual.out"; then
            problems="$problems dump${strip_option}"
        fi
        rm -f "$dump_directory/expected.out" "$dump_directory/actual.out"
    done

    if [ -z "$problems" ]; then
        echo "PASS $file"
    else
        echo "FAIL $file ($problems )"
        if [ "$expected" != "$actual" ]; then
            diff <(printf '%s\n' "$expected") <(printf '%s\n' "$actual") | head -n 20
        fi
        failures=$((failures + 1))
    fi
done

if [ "$failures" -ne 0 ]; then
    echo "$failures file(s) failed"
    exit 1
fi
