# NickServ IRCOP WHOIP — v2.2.0

## Objective and authorization

Add NickServ `WHOIP <ip>` for IRC operators as part of release v2.2.0. It finds all registered nicknames whose persisted `lastConnectIp` exactly matches the supplied IPv4 or IPv6 address. The user authorized the service version bump from v2.1.3 to v2.2.0 and wants v2.2.0 release notes to consolidate all changes from v2.1.1 inclusive through this work.

The user explicitly authorized implementation and selected these behaviors:

- Search the persisted last identified connection IP, even though it is updated at QUIT and may be stale.
- Protect the command with a dedicated `nickserv.whoip` permission.
- Return all results in one invocation, without pagination.
- Use test-first RED → GREEN.
- Deliver through one GitHub issue and one PR to `main`, then merge that PR.
- Remove the GitHub Release entries and Git tags `v2.1.1` and `v2.1.2`; consolidate their release changes into v2.2.0. Do not rewrite or delete the underlying Git commit history.

## Scope and constraints

- Search `RegisteredNick.lastConnectIp`, which is already persisted; do not add a migration, live-network search, or IP history.
- Validate a single IPv4/IPv6 literal and normalize it to the `inet_ntop` form used by persistence before lookup. Reject invalid IPs and extra arguments with the localized syntax response.
- Include all nick account statuses whose stored IP matches; omit records with no stored IP. Return nicknames only, in stable ascending nickname order, one translated reply per result; do not include hosts or IPs in result rows.
- Add `NickServPermission::WHOIP = 'nickserv.whoip'` to the IRCOP permission catalog. Preserve centralized authorization, Root bypass, and permission-filtered HELP.
- Audit valid searches with the canonical queried IP and match count; do not put the full result list into audit metadata.
- Add all user-visible syntax/help/result strings in all 14 supported locales; register the tagged command and update the service-command lifecycle coverage. Set `services.version` to `v2.2.0`, add cumulative v2.2.0 changelog notes covering changes since v2.1.1 inclusive, and update CTCP VERSION tests/expectations. Preserve historical local changelog entries unless later authorized otherwise.
- Preserve NickServ ownership: typed Application query/use case → NickServ-owned `Port/Out` → Doctrine adapter → translated IRC command adapter.
- Remote scope is requested: create one issue, create one PR to `main`, merge it, and delete GitHub Releases and tags `v2.1.1`/`v2.1.2`. Execution is pending the user providing the exact `owner/repo` and explicitly authorized GitHub credential/session. Do not inspect remotes, GitHub, or ambient credentials before then. No new v2.2.0 GitHub Release/tag publication has been requested explicitly. The issue must be nonduplicate and `status:approved` before PR creation; the PR must close that issue, use the repository template, carry exactly one `type:*` label, and pass automated checks. If the measured PR diff exceeds 400 changed lines, one PR requires explicit maintainer acceptance of `size:exception` under the repository delivery skill.

## Route, tests, and delivery

- Branch: `feat/ircop-list` (existing non-default feature branch; worktree was clean before this task).
- Route: delegated direct. Mapping trigger met in the planning phase by delegated read-only mapping across persistence, command, permission, HELP, audit, translations, and tests. Writer trigger applies to both tasks because each spans multiple non-trivial files; parent owns contracts, tracker, independent verification, and commits.
- Effective TDD: enabled, test-first RED → GREEN, selected by the user for this feature (“usando la agentica”). Runner: `./vendor/bin/phpunit --no-coverage --display-all-issues` with focused paths. Observe RED before production edits per task.
- RDD: globally disabled per `gentle-ai review mode status`; do not assess or start reviews. Delivery authority is `disabled/unmanaged`.
- Forecast: approximately 525 authored changed lines (additions + deletions; generated files excluded), including implementation, tests, 14 locales, wiring/help integration, and release metadata. Advisory estimate only. Delivery strategy: `single-pr`, selected by the user's explicit request for one issue and one PR. The work-unit commits will be delivered in that PR. RDD remains `disabled/unmanaged`; do not start reviews.
- Final checks: focused tests during each task; changed-file PHP syntax; container and YAML lint; PHPStan max; PHP-CS-Fixer dry-run/inspection; architecture gate if configured; `git diff --check`; one final full coverage run for this feature: `./scripts/check-coverage.sh 100 --issues`. The previous LIST feature's coverage run/failure is historical evidence and is not this feature's final run.

## Tasks

- [x] **T1 — Add exact stored-IP query.** Added `FindNicknamesByLastConnectIp`, its handler/interface, a narrow repository port method, and one Doctrine scalar query selecting nickname strings for exact `lastConnectIp`, ordered by `nicknameLower` and `id`. Tests cover IPv4/IPv6, multiple matches, stable order, other/null IPs, pending accounts, and empty results. RED: 33 tests/67 assertions with 4 expected errors before production. GREEN: `./vendor/bin/phpunit --no-coverage --display-all-issues tests/NickServ/Application/UseCase/Whoip/FindNicknamesByLastConnectIpHandlerTest.php tests/NickServ/Adapter/Out/Persistence/Doctrine/RegisteredNickDoctrineRepositoryTest.php` passed, 33 tests/76 assertions. Writer reports changed-file PHP lint, focused PHPStan max, PHP-CS-Fixer dry-run, and `git diff --check` passed. Runtime harness: N/A (application/persistence query has no live IRC boundary). Closed in work-unit commit `0f47c2a9`. Route: delegated direct; writer trigger: Application contract plus repository adapter/tests.
- [ ] **T2 — Add WHOIP IRCOP command and cumulative v2.2.0 release integration.** Implemented command parsing/canonicalization, output/audit, `nickserv.whoip` authorization, permission-filtered HELP/routing, 14 translations, registry/lifecycle coverage, visible service version, CTCP expectations, and cumulative v2.2.0 notes for all changes since v2.1.1 inclusive. TDD RED: 46 focused tests with 11 expected errors and 2 failures before production edits. GREEN: writer rerun passed 83 tests/1,619 assertions; parent independently ran the selected feature/regression suite, 79 tests/1,532 assertions. PHP lint passed on all 11 changed PHP paths; PHP-CS-Fixer dry-run passed (0/11 fixable); container lint, all-YAML lint (441 files), PHPStan max, and `git diff --check` passed. Final gate `./scripts/check-coverage.sh 100 --issues` passed (exit 0): 5,769 tests/28,698 assertions; its rounded summary says 100.00%, while Clover reports 20,381/20,382 lines (99.99%), with one uncovered defensive `inet_ntop`-failure return after successful `inet_pton`. The live bot script was not run: it can connect to arbitrary IRC hosts and executes state-mutating commands; no disposable target was authorized. Work-unit commit pending. Route: delegated direct; writer trigger: command, locale, security/HELP/config, tests, and changelog span multiple non-trivial files.
- [ ] **T3 — Deliver one GitHub issue/PR and consolidate remote release records.** After exact remote destination and credential/session authorization: create a nonduplicate issue, have it labeled `status:approved`, open one template-compliant PR to `main` that closes the issue and carries exactly one `type:*` label, wait for automated checks, then merge; delete GitHub Releases and tags `v2.1.1`/`v2.1.2`. If measured PR diff exceeds 400 changed lines, one PR additionally requires explicit maintainer acceptance of `size:exception`; otherwise the delivery strategy must be revisited. Do not rewrite commit history or create a new v2.2.0 GitHub Release/tag without explicit authorization. No remote calls before exact destination and account/session authorization.
- [x] **T4 — Commit authorized Composer development-tool updates separately.** Preserved the existing updates in `composer.json` and `composer.lock` as a separate work-unit commit. The manifest raises development-tool constraints (PHP-CS-Fixer, PHPStan, PHPStan-PHPUnit, PHPUnit); the lock updates matching/transitive package versions. `composer validate --no-check-publish` and staged diff check passed. Closed in work-unit commit `46b2046a`, separate from WHOIP source. Route: direct inline; the existing paired manifest/lock diff was scoped and explicitly requested by the user.

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
- User selected one issue and one PR merged to `main`; this resolves delivery strategy as `single-pr`. Remote actions remain pending exact `owner/repo` and explicit GitHub credential/session authorization.
- T4 is complete: Composer-only commit `46b2046a`; validation passed.
- T2 is fully implemented and the final coverage gate passed; the work-unit commit remains pending.
- Parent independently observed the focused feature/regression suite pass: 79 tests, 1,532 assertions; changed PHP lint, PHP-CS-Fixer dry-run (11 paths), container lint, all 441 YAML files, PHPStan max, and `git diff --check` passed.
- Next: commit T2 as its work unit and continue local-only until GitHub destination/session authorization and any required single-PR size exception are supplied.

## Relevant files

- `src/NickServ/Domain/Entity/RegisteredNick.php` — stored last connection IP and normalization.
- `src/NickServ/Application/Port/Out/RegisteredNickRepositoryInterface.php` — consumer-owned query boundary.
- `src/NickServ/Adapter/Out/Persistence/Doctrine/RegisteredNickDoctrineRepository.php` — query adapter.
- `src/NickServ/Adapter/In/Irc/Command/UseripCommand.php` — IRCOP IP-command/audit precedent.
- `translations/nickserv.<locale>.yaml` — all supported NickServ translations.
- `config/services.yaml`, `CHANGELOG.md`, `tests/Irc/Adapter/In/Event/CtcpVersionResponderTest.php`, `tests/Irc/Adapter/In/Event/CtcpHandlerTest.php` — visible version, release notes, and CTCP VERSION expectations for v2.2.0.
