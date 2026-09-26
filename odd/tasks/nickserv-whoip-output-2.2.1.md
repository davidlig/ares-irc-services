# NickServ WHOIP output fix — v2.2.1

## Objective, problem and authorization

User approved one heading followed by plain nicknames, one per line, instead of repeating the result label. User requested patch release v2.2.1, then explicitly requested removing both the GitHub release 2.2.0 and its tag. Publish the replacement first; delete the release plus remote/local v2.2.0 tags, preserving Git commit history.

## Scope and acceptance

- For any nonempty WHOIP result emit one localized header, then one plain nickname per line, preserving handler order and all matches without pagination.
- Empty searches emit only existing localized empty feedback; no header. Invalid input, permissions, HELP and auditing remain unchanged.
- Use existing replyRaw for nickname rows; add localized heading and remove obsolete result key in all 14 languages. Spanish heading: Apodos coincidentes:
- Set visible service version and CTCP expectations to v2.2.1; prepend dated 2026-09-27 changelog fix note, retaining historical 2.2.0 section.
- No Composer, migration, domain/application, permission or architecture changes.
- Deliver through approved issue, one PR to main and normal merge; publish stable latest v2.2.1 at tested merge. Remove GitHub release and remote/local tag v2.2.0 only after replacement verified. Retain prior release details in cumulative GitHub 2.2.1 notes.
- Remote destination/session: existing user-approved origin davidlig/ares-irc-services and active gh owner session; current patch release request continues that workflow. No direct production access or protection bypass; normal main CI deployment applies.

## Route and verification configuration

- Branch: fix/nickserv-whoip-output from origin/main bcefcc5b; preserve previous branch/local completion record.
- Delegated direct: mapping trigger (command, tests, 14 locales, version/CTCP); one bounded writer for complete patch (writer trigger: 2+ nontrivial files).
- TDD enabled, inherited from WHOIP feature's recorded configuration. Observe RED before production edits, then GREEN and refactor. Runner: ./vendor/bin/phpunit --no-coverage --display-all-issues.
- RDD disabled by global mode, verified at start; do not start native reviews.
- Forecast 120–180 authored changed lines, approximately 220 including tracker; delivery strategy single-pr, within 400 heuristic. Reassess actual count before commit if it grows.
- Focused tests: WhoipCommandTest, ServicesListIntegrationTest, CtcpVersionResponderTest, CtcpHandlerTest and new locale regression coverage. Run full coverage exactly once locally at final verification, not during development.

## Tasks

- [x] **T1 — Implement presentation fix and v2.2.1 metadata.** One localized header plus raw nickname rows; all 14 locales; empty/one/multiple/locale tests; version/CTCP updates and changelog. Route delegated direct, mapping/writer triggers described above. Observe focused RED/GREEN, lint/diff. Closed in `4385def6` (89 additions/32 deletions, 21 files). RED: 53 tests/914 assertions with 17 expected failures before production changes. GREEN: writer 57 tests/1,006 assertions including translation catalog checks; parent independently reran focused feature/version suite 53 tests/919 assertions. Five PHP syntax checks, focused PHPStan max, fixer dry-run 0/5 and diff integrity passed. Single-result, multi-result, empty output and all 14 actual locale renderings covered.
- [x] **T2 — Verify final candidate.** Parent independently checks PHP syntax, container/YAML lint, PHPStan max, fixer, architecture, Composer validation, and ./scripts/check-coverage.sh 100 --issues once. Inspect any formatter changes; focused/full tests must have no failures or issues and exact 100% line coverage. Route inline orchestration of deterministic gates. All final local checks passed: five changed PHP syntax checks, container lint, 441 YAML files, PHPStan max, fixer (0/1,939 changed), architecture (0 violations/skips/warnings/errors), Composer strict validation, diff integrity. Full coverage ran exactly once: 5,792 tests/28,737 assertions with no issues and exactly 20,381/20,381 lines (100%).
- [ ] **T3 — Deliver v2.2.1 and retire release 2.2.0.** Create nonduplicate approved issue, PR with type:bug, watch all checks, merge preserving commits, publish latest stable v2.2.1/tag at exact tested main SHA, then remove release record and remote/local tags v2.2.0. Verify old release/tag absent and commit history retained. Observe normal main deployment. Route inline authorized GitHub operations; no credential or policy changes. Record final remote evidence in issue and memory; final task evidence may be local-only after merge.

## Evidence and next step

- Mapper checked CodeGraph then exact command/test files and relevant version references. Existing replyRaw supports literal nickname rows with normal notifier formatting.
- Created approved bug issue https://github.com/davidlig/ares-irc-services/issues/9; closed #7 is prior scope, not a duplicate.
- GitHub allows blank issues, no templates, existing status:approved label. Create only necessary type:bug label under the owner's release workflow.
- Next: T3 push verified candidate, open PR closing approved issue #9, watch CI, merge, publish replacement, and remove old release/tag. Live IRC bot checks skipped because no disposable target was authorized.

## Relevant files

- src/NickServ/Adapter/In/Irc/Command/WhoipCommand.php
- tests/NickServ/Adapter/In/Irc/Command/WhoipCommandTest.php
- translations/nickserv.{ca,de,el,en,es,eu,fr,gl,it,nl,pl,pt,ro,tr}.yaml
- config/services.yaml
- tests/Bootstrap/ServicesListIntegrationTest.php
- tests/Irc/Adapter/In/Event/CtcpVersionResponderTest.php
- tests/Irc/Adapter/In/Event/CtcpHandlerTest.php
- CHANGELOG.md
