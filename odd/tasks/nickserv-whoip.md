# NickServ IRCOP WHOIP — v2.2.0

## Objective and authorization

Add NickServ `WHOIP <ip>` for IRC operators as part of release v2.2.0. It finds all registered nicknames whose persisted `lastConnectIp` exactly matches the supplied IPv4 or IPv6 address. The user authorized the service version bump from v2.1.3 to v2.2.0 and wants v2.2.0 release notes to consolidate all changes from v2.1.1 inclusive through this work.

The user explicitly authorized implementation and selected these behaviors:

- Search the persisted last identified connection IP, even though it is updated at QUIT and may be stale.
- Protect the command with a dedicated `nickserv.whoip` permission.
- Return all results in one invocation, without pagination.
- Use test-first RED → GREEN.
- Deliver through one GitHub issue and one PR to `main`, then merge that PR.
- Remove the GitHub Release entries and Git tags `v2.1.1`, `v2.1.2`, and `v2.1.3`; consolidate their release changes into v2.2.0 and publish its Release/tag. Do not rewrite or delete the underlying Git commit history.

## Scope and constraints

- Search `RegisteredNick.lastConnectIp`, which is already persisted; do not add a migration, live-network search, or IP history.
- Validate a single IPv4/IPv6 literal and normalize it to the `inet_ntop` form used by persistence before lookup. Reject invalid IPs and extra arguments with the localized syntax response.
- Include all nick account statuses whose stored IP matches; omit records with no stored IP. Return nicknames only, in stable ascending nickname order, one translated reply per result; do not include hosts or IPs in result rows.
- Add `NickServPermission::WHOIP = 'nickserv.whoip'` to the IRCOP permission catalog. Preserve centralized authorization, Root bypass, and permission-filtered HELP.
- Audit valid searches with the canonical queried IP and match count; do not put the full result list into audit metadata.
- Add all user-visible syntax/help/result strings in all 14 supported locales; register the tagged command and update the service-command lifecycle coverage. Set `services.version` to `v2.2.0`, add cumulative v2.2.0 changelog notes covering changes since v2.1.1 inclusive, and update CTCP VERSION tests/expectations. User now authorizes merging the three historical 2.1.1–2.1.3 changelog sections into 2.2.0; preserve 2.1.0 and earlier.
- Preserve NickServ ownership: typed Application query/use case → NickServ-owned `Port/Out` → Doctrine adapter → translated IRC command adapter.
- Remote scope confirmed by user: use this repository origin (`davidlig/ares-irc-services`) and active `gh` session (`davidlig`) for one approved issue, one PR to main with explicit `size:exception`, merge, publish v2.2.0 Release/tag, and delete old GitHub Releases/tags v2.1.1, v2.1.2, v2.1.3. Preserve commit history. Publish the replacement before deleting old records; respect existing CI checks. User explicitly accepted the single-PR size exception.

## Route, tests, and delivery

- Branch: `feat/ircop-list` (existing non-default feature branch; worktree was clean before this task).
- Route: delegated direct. Mapping trigger met in the planning phase by delegated read-only mapping across persistence, command, permission, HELP, audit, translations, and tests. Writer trigger applies to both tasks because each spans multiple non-trivial files; parent owns contracts, tracker, independent verification, and commits.
- Effective TDD: enabled, test-first RED → GREEN, selected by the user for this feature (“usando la agentica”). Runner: `./vendor/bin/phpunit --no-coverage --display-all-issues` with focused paths. Observe RED before production edits per task.
- RDD: globally disabled per `gentle-ai review mode status`; do not assess or start reviews. Delivery authority is `disabled/unmanaged`.
- Forecast: approximately 525 authored changed lines (additions + deletions; generated files excluded), including implementation, tests, 14 locales, wiring/help integration, and release metadata. Advisory estimate only. Delivery strategy: `single-pr`, selected by the user's explicit request for one issue and one PR. The work-unit commits will be delivered in that PR. RDD remains `disabled/unmanaged`; do not start reviews.
- Final checks: focused tests during each task; changed-file PHP syntax; container and YAML lint; PHPStan max; PHP-CS-Fixer dry-run/inspection; architecture gate if configured; `git diff --check`; one final full coverage run for this feature: `./scripts/check-coverage.sh 100 --issues`. The previous LIST feature's coverage run/failure is historical evidence and is not this feature's final run.

## Tasks

- [x] **T1 — Add exact stored-IP query.** Added `FindNicknamesByLastConnectIp`, its handler/interface, a narrow repository port method, and one Doctrine scalar query selecting nickname strings for exact `lastConnectIp`, ordered by `nicknameLower` and `id`. Tests cover IPv4/IPv6, multiple matches, stable order, other/null IPs, pending accounts, and empty results. RED: 33 tests/67 assertions with 4 expected errors before production. GREEN: `./vendor/bin/phpunit --no-coverage --display-all-issues tests/NickServ/Application/UseCase/Whoip/FindNicknamesByLastConnectIpHandlerTest.php tests/NickServ/Adapter/Out/Persistence/Doctrine/RegisteredNickDoctrineRepositoryTest.php` passed, 33 tests/76 assertions. Writer reports changed-file PHP lint, focused PHPStan max, PHP-CS-Fixer dry-run, and `git diff --check` passed. Runtime harness: N/A (application/persistence query has no live IRC boundary). Closed in work-unit commit `0f47c2a9`. Route: delegated direct; writer trigger: Application contract plus repository adapter/tests.
- [x] **T2 — Add WHOIP IRCOP command and cumulative v2.2.0 release integration.** Implemented command parsing/canonicalization, output/audit, `nickserv.whoip` authorization, permission-filtered HELP/routing, all 14 translations, registry/lifecycle coverage, visible v2.2.0 service version, CTCP expectations, and cumulative v2.2.0 notes for all changes since v2.1.1 inclusive. TDD RED: 46 focused tests with 11 expected errors and 2 failures before production edits. GREEN: writer rerun passed 83 tests/1,619 assertions; parent independently ran the selected feature/regression suite, 79 tests/1,532 assertions. PHP lint passed on all 11 changed PHP paths; PHP-CS-Fixer dry-run passed (0/11 fixable); container lint, all-YAML lint (441 files), PHPStan max, and `git diff --check` passed. Final gate `./scripts/check-coverage.sh 100 --issues` passed (exit 0): 5,769 tests/28,698 assertions; rounded script summary 100.00%, Clover 20,381/20,382 lines (99.99%), with one uncovered defensive `inet_ntop`-failure return after successful `inet_pton`. The live bot script was skipped because it can connect to arbitrary hosts and runs state-mutating commands; no disposable IRC test target was authorized. Closed in work-unit commit `034312d2` (590 changed lines). Route: delegated direct; writer trigger: command, locale, security/HELP/config, tests, and changelog span multiple non-trivial files.
- [ ] **T3 — Deliver one GitHub issue/PR and consolidate remote release records.** Authorization confirmed for origin and active gh session, single-PR size exception, merge to main, publishing v2.2.0, and deleting releases/tags v2.1.1/v2.1.2/v2.1.3. Approved issue #7 created (enhancement/status:approved); size:exception label created under explicit maintainer approval. Link PR, pass automated checks, merge preserving work-unit commits, publish replacement, then remove old records. Route: inline remote orchestration, delegated read-only policy/release audit. Existing main CI triggers its deployment pipeline; do not bypass environment protections.
- [x] **T4 — Commit authorized Composer development-tool updates separately.** Preserved the existing updates in `composer.json` and `composer.lock` as a separate work-unit commit. The manifest raises development-tool constraints (PHP-CS-Fixer, PHPStan, PHPStan-PHPUnit, PHPUnit); the lock updates matching/transitive package versions. `composer validate --no-check-publish` and staged diff check passed. Closed in work-unit commit `46b2046a`, separate from WHOIP source. Route: direct inline; the existing paired manifest/lock diff was scoped and explicitly requested by the user.

- [x] **T5 — Consolidate cumulative release documentation.** Merge 2.1.1–2.1.3 sections into 2.2.0, include all feature, migration, deployment/container, and Composer changes. Route: delegated direct; preparation trigger: history/config/changelog mapping. Check historical diff and Markdown/diff integrity. No production change; TDD not applicable. Completed in `161cd96e`: 2.1.1–2.1.3 headings removed, cumulative topics verified, 2.1.0 and older preserved byte-for-byte, diff integrity passed.
- [x] **T6 — Close defensive WHOIP coverage gap.** Investigate and cover the uncovered normalization fallback using established test conventions, without suppression or weakening checks. Route: delegated direct; preparation trigger: command/test/test-convention investigation. TDD remains enabled; runner ./vendor/bin/phpunit --no-coverage --display-all-issues. Closed in `daf74e3d`: combined both normalization failure paths without exclusions or weakening validation. Characterization GREEN before refactor (13 tests/57 assertions), final focused tests 14/60, independently rerun by parent; focused Clover WHOIP 32/32 statements and 14/14 methods. Changed PHP lint, focused PHPStan max, fixer dry-run and diff check passed. Honest GREEN → refactor GREEN, no new behavior or artificial RED. Initial unsupported coverage CLI option was corrected. Full local coverage was not repeated; CI will verify final full coverage.

## Acceptance criteria

- A valid IPv4/IPv6 `WHOIP <ip>` returns every NickServ record with an exact persisted-IP match, including offline accounts and every status, in ascending nickname order; no match returns localized empty feedback.
- Invalid addresses and extra arguments are rejected; unauthorized users cannot access WHOIP, permitted IRCOP roles and Root can, and HELP visibility follows permission filtering.
- Results show nicknames only; audit records the searched IP and count but not the result list.
- All 14 translations, runtime command registration, lifecycle/HELP integration, focused tests, and applicable final quality gates pass, with failures/unavailable checks recorded honestly; the service reports v2.2.0 consistently, including CTCP VERSION.

## Progress, evidence, and next step

- Design recovered from Engram observation `nickserv/whoip-command-design`; the repository mapping confirmed that `lastConnectIp` is updated from an identified connection when it quits and is normalized with `inet_ntop`.
- User confirmed v2.2.0 as the release target and directed cumulative notes since v2.1.1 inclusive. A backward-compatible command addition fits a MINOR increment under SemVer.
- Tracker and full Engram mirror were created/read back before source edits. T1 is implemented, independently verified, and committed as `0f47c2a9`.
- `composer.json` and `composer.lock` had been modified outside WHOIP; the T1 writer reports it did not edit them or run Composer. The user explicitly authorized a separate Composer commit. These changes are now committed as `46b2046a` and remain separate from WHOIP source.
- User selected one issue and one PR merged to `main`; this resolves delivery strategy as `single-pr`. Remote scope confirmed in latest user response for configured origin and active gh session.
- T4 is complete: Composer-only commit `46b2046a`; validation passed.
- T2 is implemented, fully verified by focused and full-suite checks, and committed as `034312d2`.
- The full suite with coverage passed: 5,769 tests/28,698 assertions; raw Clover line coverage was 20,381/20,382 (99.99%) due to one defensive `inet_ntop`-failure return not executed for valid `inet_pton` output.
- Next: T5/T6 are complete. Approved issue https://github.com/davidlig/ares-irc-services/issues/7 created; open the single PR with approved size exception, observe checks, merge, publish 2.2.0, and retire three previous release/tag names. Remote inspection verified v2.1.1/v2.1.2 releases exist; v2.1.3 release is absent (check tags separately).

## Relevant files

- `src/NickServ/Domain/Entity/RegisteredNick.php` — stored last connection IP and normalization.
- `src/NickServ/Application/Port/Out/RegisteredNickRepositoryInterface.php` — consumer-owned query boundary.
- `src/NickServ/Adapter/Out/Persistence/Doctrine/RegisteredNickDoctrineRepository.php` — query adapter.
- `src/NickServ/Adapter/In/Irc/Command/UseripCommand.php` — IRCOP IP-command/audit precedent.
- `translations/nickserv.<locale>.yaml` — all supported NickServ translations.
- `config/services.yaml`, `CHANGELOG.md`, `tests/Irc/Adapter/In/Event/CtcpVersionResponderTest.php`, `tests/Irc/Adapter/In/Event/CtcpHandlerTest.php` — visible version, release notes, and CTCP VERSION expectations for v2.2.0.

## Delivery boundary

- One maintainer-approved `size:exception` PR from `feat/ircop-list` to `main`, including all work-unit commits after the current main base through release preparation. Initial diff: 86 files, 3,296 additions + 89 deletions (including lockfile); final count recorded in PR.
- Composer commit `46b2046a` must remain separately reachable; use merge, not squash.
- Final remote evidence will be recorded in issue #7 and Engram after publication; this committed checklist captures pre-merge state.
