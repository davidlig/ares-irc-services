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

- [ ] **T1 — Align Ares UDB 4 wire counts.** Implement an adapter-local structural-prefix counter, cache counts per captured round snapshot for outbound INF/MANIFEST ACK, validate inbound INF against staged structural nodes, and update/add focused tests. Acceptance: unchanged blocks advertise C=9/N=14/S=7 for the observed fixtures; nested bootstrap with correct structural count succeeds, wrong count fails; digest and SQL logical count remain unchanged. Checks: focused PHPUnit, PHP syntax, container/YAML lint, PHPStan max, CS fixer, architecture gate, final full coverage once. Work-unit commit: pending. Runtime harness: N/A until separate authorization for remote deployment; local wire tests exercise the boundary. Rollback boundary: remove counter and its coordinator/takeover wiring with associated tests.

## Progress and next step

- Branch: `fix/udb-wire-node-count` from `c3ccfc54`; worktree clean at start.
- Next: delegate T1 implementation, run checks, commit and record exact evidence. Mirror this document to Engram topic `odd/udb-wire-node-count/tasks` after each update.
