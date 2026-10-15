#!/usr/bin/env bash
# The scorer of the speed score run: bench/score/score.php in a heavy-run slot
# (bench/score/heavy.sh: a 3 GB scope, an 8 MB C stack). Run it from the root
# of a checkout:
#
#   bench/score/score.sh            the full scorer (benchmarks, then the gates)
#   bench/score/score.sh --quick    the benchmarks only, 3 rounds
#
# The last line of its output is the score (lower is better); a failed gate
# prints its reason on stderr and exits 1 with no score.

set -u
directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$directory/heavy.sh" php "$directory/score.php" "$@"
