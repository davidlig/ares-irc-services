# LIST/WHOIP database performance — v2.2.2

## Objective and authorization

Implement the accepted plan: optimize NickServ WHOIP and NickServ/ChanServ LIST while preserving current results, including Unicode and each database engine's matching semantics. User authorized implementation after planning. Target release is v2.2.2, with cumulative v2.2.1 notes; retire the v2.2.1 release and both tags only after the replacement is verified. User explicitly confirmed remote delivery using the current gh session for davidlig/ares-irc-services: issue/PR, branch push, checked merge, v2.2.2 publication, then retiring v2.2.1 release and remote/local tags. No SSH/direct production access.

## Scope and invariants

- WHOIP: portable XML/migration index `idx_registered_nicks_whoip(last_connect_ip,nickname_lower,id)`; unchanged scalar query, ordering and all-results behavior.
- LIST: retain pattern normalization, escaped LIKE, only `*` as wildcard, exact totals, numbered pages, ordering and batched founder lookup.
- SQLite: indexes on `LOWER(key) COLLATE NOCASE`; PostgreSQL: `LOWER(key) text_pattern_ops`; MariaDB/MySQL: indexed virtual `list_search_key` with source column charset/collation, length 32/64.
- Existing repositories own private shared count/page predicates, DBAL count and ORM NativeQuery/ResultSetMappingBuilder pages. No Domain/Application/port changes.
- Native LIST schema extensions are migration-owned, not representable by XML/schema:update. Preserve all original indexes and data. Roll back code before removing helper columns.
- Out of scope: cursor pagination, removing exact counts, hydration redesign, redundant-index cleanup, new command semantics, direct production access.

## Workflow and delivery

- Branch: `fix/list-whoip-db-performance`, from main `b4e9ada8`.
- TDD: enabled, inherited from documented WHOIP/LIST feature configuration (`odd/tasks/nickserv-whoip-output-2.2.1.md:22`). Observe RED before production changes, then GREEN/refactor. Runner: `./vendor/bin/phpunit --no-coverage --display-all-issues`.
- RDD: disabled/unmanaged, `gentle-ai review mode status` reports global OFF. Do not enable or run native review.
- Delivery strategy: `single-pr`, user explicitly approved >400-line size exception. Forecast: 560–890 authored lines plus release metadata. Generated files excluded. One approved issue and one PR; no chain. Running counts and commit evidence below.
- Implementation route: delegated direct for T1–T4 (each includes multiple non-trivial files and preparation reads); parent owns tracking, independent checks and commits. Advisory ~400 lines per task is not a cap; retain coherent behavior, tests and docs.

## Tasks

- [ ] **T1 — WHOIP index and regression proof.** Delegate mapping/writer: XML, migration and tests. Verify IP lookup, null/missing/multiple matches, IPv4/IPv6, deterministic order, migration up/down and index access. Commit behavior with tests.
- [ ] **T2 — Compatible LIST queries and native schema extensions.** Delegate writer: both repositories, migration and tests. Use original LOWER semantics; exact equality plus escaped LIKE residual (SQLite equality NOCASE); wildcard LIKE. Compare old/new outputs including Unicode and escaping. No new inner-layer contracts. Commit behavior with tests and persistence contract documentation.
- [ ] **T3 — Migrated database matrix and query-plan proof.** Delegate writer: isolated migration-backed tests and CI SQLite/MariaDB11.4/PostgreSQL17/MySQL8.4. Explicit disposable test DSN only; no ambient production DATABASE_URL. ~50k synthetic rows, updated statistics, selective EXPLAIN checks without timing thresholds. Migration up/down/reapply and semantic equivalence per engine. Commit checks with CI integration.
- [ ] **T4 — v2.2.2 metadata and final local verification.** Delegate version config, CTCP expectations, changelog and cumulative release documentation. Parent runs final gates in order. Keep historical changelog entries. Commit metadata with tests.
- [ ] **T5 — Authorized remote delivery.** Parent inline orchestration after destination/session confirmation: approved issue, one PR with size exception, checks, merge, normal main deployment, v2.2.2 tag/release at verified SHA, then delete v2.2.1 release and remote/local tags. Preserve prior SHA/history and all unrelated releases/tags. If replacement fails, do not retire predecessor.

## Acceptance and checks

- Tests: exact, prefix, leading/multiple wildcards, Unicode/case, `%`, `_`, `!`, `?`, backslashes, trailing spaces, all states and empty/out-of-range pages; legacy/new result/count equivalence within each engine.
- Baseline audit: 118 focused tests, 972 assertions passed; not new implementation evidence.
- SQL proof: migrated schemas, safe disposable data, index search for WHOIP/exact/selective prefix; broad patterns and deep OFFSET remain known limitations. Never equate schema-only or mocked tests with live-engine proof.
- Per task: focused tests without coverage, changed PHP syntax, focused static/style checks; record all failed/skipped/unavailable checks.
- Final order: PHP lint; container lint; YAML lint; PHPStan max; architecture gate; PHP-CS-Fixer; full `./scripts/check-coverage.sh 100 --issues` exactly once locally. Zero failures/warnings/notices/skips/incomplete/risky/deprecations.
- No production migration execution outside authorized deployment workflow. Backup remains operator-managed; index creation can lock tables and requires normal deployment precautions.

## Evidence and progress

- Planning complete; local main clean before branch creation. Runtime authoritative session identity unavailable: Engram writes omit session_id.
- Remote scope confirmed by user: current gh session, davidlig/ares-irc-services, full planned delivery; no SSH/direct production access.
- Work-unit commits: none yet. Authored changed lines: 0.
- Mirror: `odd/list-whoip-db-performance/tasks`; synchronized at task checkpoints and verified by readback.

## Next step

T1 implementation after initial tracker/mirror readback. Parent owns subsequent evidence and mirror updates.
