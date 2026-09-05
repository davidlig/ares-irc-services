#!/usr/bin/env bash
# Single-command verification: runs the full PHPUnit suite WITH coverage and
# fails if line coverage is below the minimum. Replaces the "run phpunit,
# then run check-coverage" two-pass pattern — the suite executes exactly once.
#
# Usage:
#   ./scripts/check-coverage.sh [MIN_PERCENT]              # phpunit default output
#   ./scripts/check-coverage.sh [MIN_PERCENT] --issues     # PHPUnit --display-all-issues
#
# Examples:
#   ./scripts/check-coverage.sh 100
#   ./scripts/check-coverage.sh 100 --issues
set -e

MIN_PERCENT="${1:-0}"
shift || true
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLOVER="${ROOT}/var/coverage/clover.xml"

cd "$ROOT"

# Forward remaining flags to PHPUnit; "--issues" is shorthand for
# --display-all-issues, anything else is passed through unchanged.
EXTRA_ARGS=()
for arg in "$@"; do
    if [[ "--issues" == "$arg" ]]; then
        EXTRA_ARGS+=("--display-all-issues")
    else
        EXTRA_ARGS+=("$arg")
    fi
done

./vendor/bin/phpunit --coverage-clover="$CLOVER" --coverage-filter=src --do-not-cache-result "${EXTRA_ARGS[@]}"

if [[ ! -f "$CLOVER" ]]; then
    echo "Coverage report not found. Run PHPUnit with coverage (PCOV or Xdebug)." >&2
    exit 2
fi

# Project-level metrics: the <metrics> line that has files= (project aggregate)
METRICS_LINE=$(grep -E 'files="[0-9]{3,}"' "$CLOVER" | tail -1)
COVERED=$(echo "$METRICS_LINE" | sed -n 's/.*coveredstatements="\([0-9]*\)".*/\1/p')
TOTAL=$(echo "$METRICS_LINE" | sed -n 's/.*[^d]statements="\([0-9]*\)".*/\1/p')

if [[ -z "$COVERED" || -z "$TOTAL" || "$TOTAL" -eq 0 ]]; then
    echo "Could not parse coverage from $CLOVER" >&2
    exit 2
fi

PERCENT=$(awk "BEGIN { printf \"%.2f\", ($COVERED / $TOTAL) * 100 }")

BELOW=$(awk "BEGIN { print ($PERCENT < $MIN_PERCENT) ? 1 : 0 }")
if [[ "$BELOW" -eq 1 ]]; then
    echo "Coverage $PERCENT% is below minimum ${MIN_PERCENT}% (lines: $COVERED/$TOTAL)." >&2
    echo "Per-line inspection: ./vendor/bin/phpunit --coverage-html var/coverage/html" >&2
    exit 1
fi

echo "Coverage: $PERCENT% ($COVERED/$TOTAL lines). Minimum ${MIN_PERCENT}% required."
exit 0
