# UDB wire node count

## Objective and problem

Stop unchanged UDB 4 MANIFEST rounds from requesting full C/N transfers because Ares announces logical SQL row counts (C=7, N=8) while UDB compares structural node counts (C=9, N=14). Align the reverse INF bootstrap count check with the same wire contract.

## Authorized scope and constraints

- User explicitly authorized implementation and delegated the formula choice. Change only the Ares `UnrealUdb` protocol adapter and its tests; do not change UDB, SQL schema or stored logical counts.
- No remote deployment, restart, write or file transfer is authorized.
- Count unique nonempty canonical path prefixes (excluding block root) using existing UDB key identity, including K::F case-sensitive patterns. Keep SHA, PUT records, `UdbBlockState.recordCount` and `maxStageRecords` semantics unchanged.

## Approach, route and delivery

- One cohesive work unit; forecast about 220 authored changed lines, below the ~400-line delivery threshold. Delivery strategy: `ask-on-risk`; no PR requested.
- Route: delegated direct. Trigger evidence: one new counter plus nontrivial coordinator, takeover and test edits (writer trigger); prior read-only mapping covered 4+ files (mapping/preparation triggers).
- Effective TDD: off (no project/session TDD setting found); runner: `./vendor/bin/phpunit --no-coverage --display-all-issues`. Ordinary focused tests required.
- RDD: disabled by default (`gentle-ai review mode status`); ordinary repository verification applies.

## Tasks

- [x] **T1 — Align Ares UDB 4 wire counts.** Added an adapter-local structural-prefix counter, cached counts per snapshot for outbound INF/MANIFEST ACK, validated inbound INF against staged structural nodes, and covered the protocol behavior in tests. Acceptance observed: fixture counts C=9/N=14/S=7, exact outbound counts, nested bootstrap acceptance/rejection, unchanged digest and persisted logical count. Checks: focused PHPUnit 157 tests / 786 assertions; PHP syntax on six changed PHP files; container lint; 124 YAML files linted; PHPStan max; CS fixer fixed 0/1901 files; architecture gate 0 violations; full coverage once: 5680 tests / 28070 assertions, 100.00% (19927/19927 lines), no issues; `git diff --check` clean. Work-unit commit: `4b2f2cd83cbf1d04516e8747f0bd7a453b2193d8`. Runtime harness: N/A without separate remote deployment authorization; local wire tests cover the boundary. Rollback boundary: revert the counter and its coordinator/takeover wiring with associated tests.

## Progress and next step

- Branch: `fix/udb-wire-node-count` from `c3ccfc54`; worktree clean at start.
- Actual work-unit size: 192 authored changed lines (179 additions, 13 deletions), below the delivery threshold. RDD outcome: disabled/unmanaged; no native review run.
- Next: retain this branch for user review/deployment decision. Live confirmation after a separately authorized deployment: unchanged MANIFEST advertises C=9/N=14 and triggers no C/N RES or mode churn. Mirror this document to Engram topic `odd/udb-wire-node-count/tasks` after each update.
