# ChanServ private INFO

## Objective and authorization

Implement the requested ChanServ INFO presentation changes and persistent `SET #channel PRIVATE {ON|OFF}` option. When enabled, channel information is visible only to its identified founder, identified users with an ACCESS entry, and IRC operators. Everyone else receives the localized private-information notice and no INFO details.

## Scope and constraints

- Show only enabled TOPICLOCK, MLOCK, SECURE, and IRCOPONLY values; omit the options row if all are disabled.
- Move URL and Email after last-topic and topic-setter rows.
- PRIVATE defaults OFF for existing and newly registered channels; persist it compatibly.
- Treat any registered ACCESS entry as authorized; founder and IRCop bypass ACCESS lookup. IRCop bypass was explicitly confirmed by the user.
- Keep authorization orchestration in ChanServ Application; do not add Doctrine/repository access to the IRC adapter.
- Add/update all user-facing translations in the 14 supported locales.
- Preserve behavior while refactoring `InfoCommand::execute()` into a clear orchestration method with focused presentation helpers; avoid nested conditional chains.
- No remote operations or publication/PR/merge requested.

## Route, tests, and delivery

- Route: delegated direct. Mapping trigger fired because implementation understanding spans 4+ files; writer trigger fired because multiple non-trivial production/test/translation files change. One read-only mapping worker completed before writer delegation.
- Effective TDD: enabled by explicit user choice in this session (“el que quieras”), selecting test-first. Source: user choice. Runner: `./vendor/bin/phpunit --no-coverage --display-all-issues` with focused ChanServ tests; observe RED before production changes, then GREEN and refactor.
- RDD: disabled (global decision), verified with `gentle-ai review mode status`; delivery reports disabled/unmanaged.
- Forecast before the refactor: approximately 740 authored changed lines (including the task document; generated files excluded), including tests and 14 locale updates; T4 adds a focused presentation refactor. Delivery strategy: ask-on-risk; ask once for `stacked-to-main` or `feature-branch-chain` if confirmed forecast/running total exceeds ~400 before a work-unit commit. No PR creation or remote publication.
- Final checks: PHP syntax, container lint, YAML lint, PHPStan max, PHP-CS-Fixer, architecture gate if configured, full coverage exactly once (`./scripts/check-coverage.sh 100 --issues`), and `git diff --check`.

## Tasks

- [x] **T1 — Add persisted PRIVATE setting and SET command.** Extend channel state/persistence and the existing SET option flow, default OFF, and cover the domain, command parsing/authorization, storage mapping/migration, help, and all locale strings. Route: delegated. Trigger evidence: 4+ file preparation/mapping and multiple non-trivial implementation/test files.
- [x] **T2 — Enforce PRIVATE and update INFO presentation.** Carry the identified requester facts into the application query, allow founder/ACCESS/IRCop, deny others before emitting INFO content, then implement enabled-only options and the URL/Email order. Update translations and adapter/use-case tests. Route: delegated. Trigger evidence: multiple non-trivial files and shared contracts with T1; same writer owns the cohesive change.
- [x] **T3 — Reverify after INFO refactor and prepare work-unit delivery.** Rerun focused INFO/SET tests, PHPStan, formatting, architecture and full coverage after T4; inspect diff and record commit identity. Previously completed checks remain evidence but are reopened because T4 changes production code. Route: parent coordination/checks.
- [x] **T4 — Refactor ChanServ INFO presentation.** Decompose mixed query/state/presentation work into a short orchestrator and cohesive rendering helpers; use guard clauses to avoid nested conditionals and preserve all current output/authorization behavior. Route: delegated direct; this focused one-file writer assignment did not meet the mandatory multi-file trigger. Reopen T3 checks after implementation.

## Acceptance criteria

- INFO prints no OFF option names and emits no options row when none are enabled.
- URL and Email appear after the last-topic and topic-setter rows.
- PRIVATE ON persists across reloads; OFF restores public INFO visibility; existing channels default to OFF.
- Private INFO denies unidentified/non-ACCESS users with the localized notice and no channel details; identified founder, identified ACCESS user, and IRCop can see INFO.
- SET help/validation and all 14 translations are complete; all required checks are recorded honestly.
- `InfoCommand::execute()` remains a short orchestrator; output groups are rendered by focused private methods with guard clauses, without behavior changes or deep conditional nesting.

## Progress, evidence, and next step

- Feature branch: `feat/chanserv-private-info`, created from existing `feat/main-autodeploy` HEAD `adbeb688a0a66fec1d33c10d780553038a99ae89`; source branch was not the default branch.
- Mapping completed read-only. Existing INFO privacy only gates topic visibility and recognizes founder/IRCop; ACCESS is available through ChanServ-owned application ports. SET options are centrally registered in `SetCommand`; registered channels are XML-mapped and require an additive migration.
- The full current task document is mirrored in Engram at topic `odd/chanserv-private-info/tasks`.
- T1 complete: PRIVATE defaults OFF; `SET PRIVATE ON|OFF` is registered and persisted; Doctrine XML plus additive migration, help, confirmation messages, and tests exist. RED then GREEN observed; focused tests passed 58 tests / 137 assertions, plus container lint and diff check. Writer reports YAML lint (84 files), PHP lint, scoped PHPStan, and fixer dry-run passed; PHPStan required an approved retry outside the sandbox after a TCP listener failure.
- T1 work-unit delivery: commit `304a40cc` (`feat(chanserv): persist PRIVATE channel setting`), 365 authored changed lines. Focused tests passed 59 tests / 140 assertions. Rollback boundary is the persisted setting, SET handler/help/locale strings, Doctrine mapping/migration and their tests; runtime harness N/A because unit/adapter tests fully exercise this command without an external IRCd.
- T2/T4/T3 work-unit delivery: `c1a64976` (`feat(chanserv): refine INFO channel details`, 350 lines) holds refactor, options and INFO order; `704d49b6` (`feat(chanserv): enforce private INFO access`, 171 lines) holds application privacy authorization. `InfoCommandTest.php` passed 48 / 71 for both; full quality/coverage gates passed after these commits' contents were in the worktree. Runtime harness N/A; tests exercise adapter/application behavior in memory. Rollback each commit independently to remove only its named INFO concern.
- T2 complete: the application handler allows identified founder, identified ACCESS account, and IRCop; denies others before any INFO header/status; options are enabled-only with no empty row; URL/Email follow topic rows. All 14 locales updated. RED then GREEN observed; writer reports 47 tests / 70 assertions and clean locale/container/PHPStan/fixer/diff checks. Parent reran relevant INFO/SET/entity tests: 105 tests / 207 assertions; container lint and diff check passed.
- Initial sandbox coverage attempt could not bind local TCP test servers; first approved escalated run passed 5,715 tests / 28,292 assertions but found 2 uncovered lines (99.99%). Added tests for those branches, then final approved coverage passed 5,717 tests / 28,296 assertions with 100.00% line coverage (20,104/20,104).
- T3 verification complete except work-unit commit: focused ChanServ tests passed (105 tests / 207 assertions; targeted branch-coverage tests 51 / 83); all 441 YAML files linted; PHP syntax passed on 14 changed PHP files; container lint passed; PHPStan max and Deptrac passed; PHP-CS-Fixer dry-run found 0 fixable files; `git diff --check` clean. Final coverage successful outside sandbox: 5,717 tests / 28,296 assertions, 100.00% lines (20,104/20,104). A prior sandbox attempt had 9 TCP-listener errors; an approved escalated run passed all tests but found two uncovered lines, which were covered by T3 tests before the successful final run.
- User authorized a behavior-preserving clean-code refactor after noting `InfoCommand::execute()` was too cluttered. `execute()` now validates/loads INFO then calls `present()`; separate helpers render privacy/forbidden/pending/registered cases, section groups, optional values and footer with guard clauses. Output order/authorization behavior is unchanged.
- T4 complete: InfoCommandTest passed before and after (48 tests / 71 assertions); parent reran it successfully. PHP syntax, focused PHPStan, PHP-CS-Fixer dry-run (0 files), and `git diff --check` passed. No tests or other source files were changed. Test-first RED was not applicable to this behavior-preserving refactor; existing characterization tests were green before/after.
- T3 rerun after T4: focused suite passed 107 tests / 211 assertions; container and YAML lint, PHPStan max, CS-Fixer dry-run, Deptrac, and diff check all passed. Initial full suite passed 5,717 tests / 28,296 assertions but coverage was 99.99% (20,111/20,112); Clover identified only the null-date early return at `InfoCommand.php:278` as uncovered. Reworked `presentOptionalDate()` to guard the rendering body with `null !== $date`, preserving output while removing that uncovered return. Post-fix INFO tests passed (48 / 71); PHP syntax, container/YAML lint, PHPStan max, CS-Fixer dry-run (0 fixable), Deptrac (0 violations), and diff check passed. Final full coverage passed: 5,717 tests / 28,296 assertions and 100.00% line coverage (20,111/20,111).
- Actual authored diff before T4 was approximately 760 lines including the task document; generated files excluded. This exceeds the ~400 delivery budget. User selected `feature-branch-chain` on 2026-09-26; work-unit commits are tracked under `odd/tasks/release-2.1.2.md`.
- Engram task-document mirror now saved at topic `odd/chanserv-private-info/tasks`; the feature discovery and session summary were also recorded.
- Next: ChanServ implementation and verification are complete in commits `304a40cc`, `c1a64976`, and `704d49b6`; release metadata is committed as `bc16c8a3`. v2.1.2 is published at https://github.com/davidlig/ares-irc-services/releases/tag/v2.1.2. No PR/merge was created.

## Relevant files

- `src/ChanServ/Adapter/In/Irc/Command/InfoCommand.php` — INFO presentation and visibility boundary.
- `src/ChanServ/Adapter/In/Irc/Command/SetCommand.php` — SET dispatch, supported options, and help.
- `src/ChanServ/Application/UseCase/ShowInfo/ShowChannelInfoHandler.php` — registered channel query and view mapping.
- `src/ChanServ/Domain/Entity/RegisteredChannel.php` — persisted channel setting behavior.
- `config/doctrine/chanserv/RegisteredChannel.orm.xml` — registered-channel mapping.
- `translations/chanserv.*.yaml` — localized INFO and SET strings.
