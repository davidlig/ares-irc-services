# NickServ and ChanServ IRCOP LIST — v2.1.3

## Objective and authorization

Implement `LIST <pattern> [page]` for NickServ and ChanServ as separate IRCOP commands. NickServ displays nickname, registration date, last connection, stored last-connection IP, and status. ChanServ displays channel, founder nickname, registration date, last use, and status. Include the v2.1.3 service-version metadata update.

The user explicitly authorized implementation, requested a new feature branch, and confirmed the version bump to v2.1.3. No tag, push, PR, merge, or release publication is authorized.

## Scope and constraints

- Pattern is required; `LIST *` matches all records. Matching is case-insensitive, strict full-name glob; `*` matches any sequence in its supplied position. Do not add `?` or implicit wildcards. Therefore `*avid` does not match `davidlig`; `*avid*` does.
- Page defaults to 1; values below 1 normalize to page 1. An out-of-range page returns no rows and localized page/total information. Filter, count, and pagination in persistence; use stable ascending name order.
- Include every current NickStatus and ChannelStatus, including pending deletion and forbidden records. Render status labels in the service locale. Missing fields render as `—`.
- Use NickServ's existing persisted registration, last-seen, IP, and status fields; no migration. Keep cross-context reads behind consumer-owned application ports; ChanServ must not import NickServ Domain or persistence classes.
- Add independent `nickserv.list` and `chanserv.list` permissions through the central IRCOP policy/catalog and permission-filtered HELP. Preserve identified Root bypass semantics. Audit LIST through the existing IRCOP audit path with safe metadata; never add listed IP addresses to audit data.
- Add `NICKSERV_LIST_PAGE_SIZE=50` and `CHANSERV_LIST_PAGE_SIZE=50` in `.env` and integer config wiring. Update all required user-visible strings in all 14 supported locales.
- Update service version to `v2.1.3`, prepend the matching changelog entry, and update CTCP VERSION expectations/fixtures.
- Preserve bounded-context ownership and the service command adapter → typed Application use case → consumer-owned persistence port flow. Follow `.agents/services.md`; `.agents/services/commands.md` is not present in this checkout.

## Route, tests, and delivery

- Feature branch: `feat/ircop-list` (created from the existing non-default branch after the user requested it).
- Route: delegated direct. The mapping trigger was met by prior read-only mapping across the service command, permission, repository, entity, configuration, and test patterns. Each implementation task touches multiple non-trivial files, so the writer trigger requires a delegated writer. Keep tasks sequential where they share `config/services.yaml` or test registries.
- Effective TDD: enabled, test-first (RED → GREEN → REFACTOR), explicitly selected by the user (“sí, lo que diga la agentica,” accepting the agent's test-first recommendation). Runner: `./vendor/bin/phpunit --no-coverage --display-all-issues` with focused test paths; observe RED before each task's production edits.
- RDD: disabled by global preference, verified by `gentle-ai review mode status`. Do not initiate review or retry. Delivery is disabled/unmanaged.
- Forecast: approximately 1,000 authored changed lines (additions + deletions; generated files excluded), based on two service vertical slices, tests, 14-locale updates, integration/configuration, and version metadata. Advisory estimate only. Delivery strategy: `feature-branch-chain`, selected by the user before the first work-unit commit. This only plans future slices; do not create any PR or perform remote operations without separate authorization.
- Planned feature-branch-chain slices (planning only; no PRs or remote work authorized): (1) NickServ LIST — commit `f7c45055`; (2) ChanServ LIST and bounded bulk founder projection — commit `427d8b53`; (3) v2.1.3 metadata and cross-service integration — T3 work-unit commit pending; (4) focused tests for two coverage gaps found by the final gate — T4 work-unit commit planned. Intended dependency order is slice 1 → slice 2 → slice 3 → slice 4. If the user later authorizes pull requests, map one PR to each slice in that order.
- Final checks after all tasks: changed-file PHP syntax, container lint, YAML lint, PHPStan max, PHP-CS-Fixer dry-run/inspection, configured architecture gate if present, `git diff --check`, and exactly one final full coverage run: `./scripts/check-coverage.sh 100 --issues`. The one permitted full coverage run has already been consumed; do not rerun it.

## Tasks

- [x] **T1 — Implement NickServ LIST vertical slice.** Add typed paginated query/use case, persistence filtering/count/order, IRC command parsing/presentation, `nickserv.list` authorization/catalog/help, `NICKSERV_LIST_PAGE_SIZE`, audit-safe metadata, all NickServ locale strings, and focused/unit/adapter/registry tests. Include strict-glob, page-boundary, all-status, missing-IP/date, and authorization cases. Route: delegated direct; mapping/writer trigger evidence above. Parent observed RED/GREEN and independently verified the diff/checks. Work-unit commit: `f7c45055` (`feat(nickserv): add paginated IRCOP LIST`).
- [x] **T2 — Implement ChanServ LIST vertical slice.** Add typed paginated query/use case, persistence filtering/count/order, bulk founder-name resolution through a ChanServ-owned boundary, command/help, `chanserv.list` authorization/catalog, `CHANSERV_LIST_PAGE_SIZE`, all ChanServ locale strings, and focused/unit/adapter/registry tests. Cover strict glob, pagination, all statuses, missing founder/date, and authorization. Route: delegated direct; mapping/writer trigger evidence above. Reopened after review found that resolving distinct page founders one at a time could issue up to one query per row; completed a NickServ-owned bulk lookup and reran affected checks. Parent independently verified the diff and gates. Work-unit commit: `427d8b53` (`feat(chanserv): add paginated IRCOP LIST`).
- [ ] **T3 — Set v2.1.3 and close cross-service integration.** Update service-visible version, changelog and CTCP VERSION fixtures/expectations; verify both service page-size environment defaults, command/permission catalogs, all-locale translation completeness, and HELP/registry command-count integration. Route: delegated direct for multi-file non-trivial changes; parent owns verification and commit.
- [ ] **T4 — Cover the two edge lines identified by the full coverage report.** Add focused regression tests for filtering malformed scalar rows in the NickServ bulk nickname projection and for the immutable `ListedNickAccount` value object's constructor contract. Do not alter production behavior unless the new assertions expose a real defect. Route: delegated direct because this touches two non-trivial test files; parent owns verification and commit. This is a test-only closure task, so RED is not expected if the current behavior already satisfies the new assertions; record the observed result without manufacturing a failing run.

## Acceptance criteria

- Both commands accept exactly a required pattern and optional page; `LIST *` lists all, strict full-name `*` matching is case-insensitive, page size defaults to 50, and result ordering is stable.
- NickServ and ChanServ render the agreed pipe-separated fields, localized status, dates in the service timezone, and `—` for missing data; all statuses are searchable/listable.
- Database queries do not load every record to filter or paginate. Authorization, Root bypass, catalog, HELP filtering, and safe auditing are correct for each service.
- All 14 locales have the required command/help/result/page/status strings, and the service reports v2.1.3 consistently.
- Applicable focused checks pass with zero PHPUnit issues; all final quality gates are run and recorded honestly.

## Progress, evidence, and next step

- Branch `feat/ircop-list` exists; the worktree was clean when the branch was created and the tracking document was prepared before implementation writes.
- RDD is off globally. Test-first is selected. T1 is complete and verified: RED was observed before implementation (44 tests, 12 expected errors, 56 assertions); NickServ suite passed (1,161 tests, 4,581 assertions); translation catalog passed (4 tests, 87 assertions); container lint passed; YAML lint passed (441 files); PHPStan max passed; PHP-CS-Fixer dry-run found 0 fixable files; all affected PHP files passed `php -l`; `git diff --check` passed. Initial sandbox PHPStan invocation could not bind its local worker socket; the same full PHPStan command passed when rerun outside the sandbox.
- T1 work-unit commit: `f7c45055` (`feat(nickserv): add paginated IRCOP LIST`), created locally on `feat/ircop-list`.
- T2 test-first evidence: initial RED before production (62 tests, 615 assertions, 17 expected errors), plus a separate expected RED for pending-deletion LIST dispatch; after implementation and bulk-founder refactor, the combined ChanServ/NickServ/permission/wiring/translation suite passed (2,631 tests, 9,931 assertions), independently reproduced by parent. Container lint passed; full YAML lint passed (441 files); command tag contains `ListCommand`; full PHPStan max passed across `src/` and `tests/`; PHP-CS-Fixer dry-run found 0 of 26 affected PHP files fixable; affected PHP files passed `php -l`; `git diff --check` passed; `composer architecture` reported 0 violations, 0 skipped, 0 uncovered, 0 warnings, and 0 errors. Coverage remains reserved for final verification.
- T2 work-unit commit: `427d8b53` (`feat(chanserv): add paginated IRCOP LIST`), created locally on `feat/ircop-list`.
- The full document is mirrored in Engram at `odd/ircop-list-nickserv-chanserv/tasks`; both copies were read back and reconciled before implementation.
- T3 test-first evidence: RED observed before production edits (26 tests, 1,365 assertions, 1 expected failure while the container still reported v2.1.2). The cross-service integration set passed (2,660 tests, 11,299 assertions); full PHPStan max passed; container lint, YAML lint (441 files), `composer architecture` (0 violations/skipped/uncovered/warnings/errors), PHP-CS-Fixer dry-run (0 of 4 affected PHP files fixable), PHP syntax, and `git diff --check` passed. The single mandated full command `./scripts/check-coverage.sh 100 --issues` ran and all tests passed (5,757 tests, 28,603 assertions), but the 100% gate failed: classes 885/887 (99.77%), methods 4,489/4,491 (99.95%), lines 20,333/20,335 (99.99%). `var/coverage/clover.xml` identifies the uncovered production locations as `src/NickServ/Adapter/Out/Persistence/Doctrine/RegisteredNickDoctrineRepository.php:67` (invalid scalar projection row is skipped) and `src/NickServ/Application/UseCase/List/ListedNickAccount.php:18` (value-object constructor). These are in T1/T2 code, not T3. Do not rerun full coverage; T4 adds targeted tests, but the original full gate must remain recorded as failed.
- T3 implementation is present locally but its work-unit commit is pending; after T3 commit, record its identity here and in the mirror before T4 implementation.
- Next: commit T3, then delegate T4's two focused test additions, run the affected tests and relevant changed-file checks, update/read back this file and mirror, and commit T4. No PR/push or other remote action is authorized.

## Relevant files

- `.agents/services.md` — IRC command boundaries, permission requirements, translations, and command checklist.
- `src/NickServ/Domain/Entity/RegisteredNick.php` and `config/doctrine/nickserv/RegisteredNick.orm.xml` — available nick data including persisted last-connection IP.
- `src/ChanServ/Domain/Entity/RegisteredChannel.php` — channel status, founder identity, registration, and activity data.
- `config/services.yaml`, `.env`, `CHANGELOG.md` — page-size configuration and v2.1.3 metadata.
