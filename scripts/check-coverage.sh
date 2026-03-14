#!/usr/bin/env bash
# Check that code coverage meets a minimum percentage (lines).
# Usage: ./scripts/check-coverage.sh [MIN_PERCENT]
# Example: ./scripts/check-coverage.sh 58   # fail if line coverage < 58%
#          ./scripts/check-coverage.sh 100 # fail if not 100%
set -e

MIN_PERCENT="${1:-0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLOVER="${ROOT}/var/coverage/clover.xml"

cd "$ROOT"
./vendor/bin/phpunit --coverage-clover="$CLOVER" --coverage-filter=src --no-output --do-not-cache-result >/dev/null 2>&1

if [[ ! -f "$CLOVER" ]]; then
    echo "Coverage report not found. Run PHPUnit with coverage (PCOV or Xdebug)." >&2
    exit 2
fi

# Project-level metrics: the <metrics> line that has files= (project aggregate)
# Project aggregate is the only <metrics> with 3+ digit file count
METRICS_LINE=$(grep -E 'files="[0-9]{3,}"' "$CLOVER" | tail -1)
COVERED=$(echo "$METRICS_LINE" | sed -n 's/.*coveredstatements="\([0-9]*\)".*/\1/p')
# Match "statements=" but not "coveredstatements="
TOTAL=$(echo "$METRICS_LINE" | sed -n 's/.*[^d]statements="\([0-9]*\)".*/\1/p')

if [[ -z "$COVERED" || -z "$TOTAL" || "$TOTAL" -eq 0 ]]; then
    echo "Could not parse coverage from $CLOVER" >&2
    exit 2
fi

PERCENT=$(awk "BEGIN { printf \"%.2f\", ($COVERED / $TOTAL) * 100 }")

# Compare with awk to avoid depending on bc
BELOW=$(awk "BEGIN { print ($PERCENT < $MIN_PERCENT) ? 1 : 0 }")
if [[ "$BELOW" -eq 1 ]]; then
    echo "Coverage $PERCENT% is below minimum ${MIN_PERCENT}% (lines: $COVERED/$TOTAL)." >&2
    exit 1
fi

echo "Coverage: $PERCENT% ($COVERED/$TOTAL lines). Minimum ${MIN_PERCENT}% required."
exit 0
