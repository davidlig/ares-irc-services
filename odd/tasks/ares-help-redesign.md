# Ares service HELP redesign

## Objective

Redesign NickServ, ChanServ, MemoServ, and OperServ HELP using the approved design pack, localize the user-visible help in all 14 supported languages, improve future command/service-bot guidance, and prepare the service release version `v2.3.0`.

## Problem and why

Current HELP output uses flat command lists and parallel formatters without functional sections. The prototypes establish a common hierarchy and palette, but omit existing commands and current direct-help permission behavior is inconsistent. This work makes the four services navigable and consistent without changing command execution or business authorization.

## Authorized scope

- New local feature branch `feat/ares-help-redesign`, based on `main` at `5b3e1b58`.
- HELP presentation adapters, help metadata, HELP tests, and all 56 service translation catalogs for locales `ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`, `tr`.
- `.agents/services.md` guidance for future service commands and service bots.
- Service version metadata, release notes, and tests expecting the visible service version.
- No Domain/Application business logic, command execution, command authorization, IRC protocol behavior, design-pack edits, remote operations, tag creation, or PR creation.

## Constraints and decisions

- Preserve every implemented/registered command. Include NickServ `RESTORE` and ChanServ `LIST`, which are omitted by the HTML prototypes, in appropriate permission-filtered sections.
- Filter restricted commands in both general HELP and direct `HELP <command>` lookups. This changes help visibility only; command execution authorization is unchanged.
- Preserve `ROLE OPERCLASS` metadata/help only when the active capability supports it.
- Keep the demonstrated three HELP levels; `HELP ROLE PERMS` explains LIST/ADD/DEL/CLEAR in that response. Do not add a further `HELP ROLE PERMS ADD` lookup level.
- Use the design pack's palette and service groupings while translating natural-language descriptions for each locale; do not copy Spanish strings into other catalogs.
- TDD: disabled, explicitly selected by user. Use ordinary focused functional checks with `./vendor/bin/phpunit --no-coverage --display-all-issues`.
- Receipt-driven development: disabled by global user preference; delivery is `disabled/unmanaged`. Do not start reviews, retry assessments, or toggle the mode.
- Delivery strategy: `ask-on-risk` default; user selected `feature-branch-chain` on 2026-09-29 after the forecast exceeded 400 authored lines. The original 800–1,400-line forecast was low: HLP-01 measured 2,184 authored additions/deletions; projected feature total is now approximately 2,400 lines (generated files excluded). Cache the selected strategy and record each slice boundary and its commits; no push/PR is authorized.
- Local feature-branch chain slices: 1) HLP-01 HELP code, tests, and all translations — `d2089f4d`; 2) HLP-02 agent guidance — `47d9bef1`; 3) HLP-03 version/changelog/assertions — `68519c31`; 4) HLP-01R focused coverage follow-up — pending. The cached `feature-branch-chain` strategy still applies; no PRs or remote actions are authorized.
- Use Conventional Commits on this feature branch, with tests/docs alongside their behavior. Do not push or create a PR/tag without explicit authorization.

## Acceptance criteria

- General HELP uses stable functional group order, shared palette, service introduction, actionable detail-help hint, and appropriate admin/expiry sections.
- Command and subcommand HELP follow the prototypes, with syntax at the end and nested `ROLE PERMS` action descriptions.
- No restricted command is listed or directly described unless the sender can view it; execution authorization remains unchanged.
- All 14 locale catalogs define the new/changed keys and matching placeholders; translation and syntax remain complete for all registered commands.
- Dynamic `OPERCLASS`, NickServ timezone help, NickServ/ChanServ expiration notes, and the existing command inventory remain correct.
- All command/help metadata tests, focused tests, static checks, architecture gates, and the final 100% coverage gate pass with zero reported issues.
- Visible service version is `v2.3.0`, release notes identify 2.3.0, and version-response expectations match.
- Agent guidance tells future contributors how to add a service command and how to add a service bot without violating module boundaries.

## Tasks and progress

### HLP-01 — Complete HELP redesign in all services and locales

- [x] Add presentation-only grouping metadata, render grouped general HELP and apply consistent visibility checks to direct HELP across the four services.
- [x] Preserve documented command/subcommand output, special cases, IRC formatting, and dynamic `OPERCLASS`; add focused tests for group ordering, permission visibility, nested help, and compatibility.
- [x] Update all 56 locale catalogs in the same work unit so every new/changed HELP key is complete across all supported languages, and extend shared key/placeholder/catalog alignment assertions.
- Route: **delegated direct**. Trigger evidence: mapping required 4+ source files and was completed read-only; writer trigger applies because this task changes four formatter/context implementations, all locale catalogs, and their tests. Code and all required translations stay together to avoid shipping referenced-but-missing locale keys.
- Checks passed: focused four-service HELP tests plus shared translation alignment (`66 tests, 983 assertions`); `php -l` on all 23 changed PHP files; `php bin/console lint:yaml . --exclude vendor/ --parse-tags` (441 files, reported by delegated writer) and independent `php bin/console lint:yaml translations --parse-tags` (84 files); `git diff --check`; PHPStan max (reported by delegated writer). PHP-CS-Fixer remains pending for final verification because its prior attempt failed to open local TCP under sandbox and the retry was aborted.
- Forecast: actual HLP-01 diff is 2,184 authored additions/deletions across 79 files; selected strategy is `feature-branch-chain`.
- Progress: implemented, focused checks passed, and committed as `d2089f4d` (`feat(help): redesign service help across locales`). RDD outcome: disabled/unmanaged.

### HLP-01R — Cover remaining HELP presentation branches

- [x] Add focused tests for HELP production branches found uncovered by the first final coverage run, without changing command authorization or user-visible behavior.
- Route: **delegated direct**. Trigger evidence: read-only Clover mapping identified branches across four formatters, five context-adapter tests, and ChanServ's command HelpCommand test; changes span 2+ non-trivial files.
- Mapped cases: ungrouped visible-command fallback in all four formatter tests; synthetic admin/subgroup and IRCOP cases for MemoServ/OperServ; context-adapter visibility/group forwarding cases; distinct unknown `HELP MISSING` case in ChanServ. Keep the known-but-denied command behavior test unchanged.
- Checks passed: focused tests across the 10 mapped files (`98 tests, 225 assertions`); `php -l` on all 10 changed test files; `git diff --check`. The full coverage rerun belongs to HLP-04.
- Progress: test-only cases implemented and verified; existing known-but-denied behavior remains unchanged. Record the work-unit commit identity during final tracker closure.

### HLP-02 — Future command and service-bot agent guidance

- [x] Expand `.agents/services.md` with concise, actionable checklists covering HELP grouping/metadata, all-locale translation, permission-filtered direct/general HELP, runtime registration, bot wiring, and integration tests for new commands/services.
- Route: **direct inline**. Trigger evidence: one contributor-guide file; bounded bot/tag/wiring discovery was delegated before the edit, and the remaining checklist scope is clear.
- Checks passed: documentation review against service/architecture boundaries; `git diff --check`; lines longer than 120 characters scan returned none. Delegated discovery confirmed current service command tags and gateway/provider wiring names.
- Progress: implemented, reviewed, and committed as `47d9bef1` (`docs(agents): guide service command and bot additions`). RDD outcome: disabled/unmanaged.

### HLP-03 — Prepare release version 2.3.0

- [x] Change the visible `services.version` to `v2.3.0`, add the top `2.3.0` changelog entry, and update all visible-version assertions.
- Route: **delegated direct**. Trigger evidence: coordinated non-trivial config, release-note, and test changes.
- Checks passed: focused service-list and CTCP tests (`24 tests, 844 assertions`); `php bin/console lint:yaml config/services.yaml --parse-tags`; `git diff --check`. An initial YAML lint invocation without `--parse-tags` failed on Symfony `!tagged_iterator`, then the documented invocation passed.
- Forecast: 31 authored changed lines across 5 files.
- Progress: implemented, verified, and committed as `68519c31` (`chore(release): bump service version to 2.3.0`). RDD outcome: disabled/unmanaged.

### HLP-04 — Final verification and delivery record

- [ ] Run `php -l` on changed PHP files, `php bin/console lint:container`, `php bin/console lint:yaml . --exclude vendor/ --parse-tags`, PHPStan max, PHP-CS-Fixer (inspect resulting diff), configured architecture gate, and the final `./scripts/check-coverage.sh 100 --issues` after HLP-01R.
- [ ] Record each result, failed/skipped/unavailable checks, work-unit commit IDs, and final next step here; update the Engram mirror after each task.
- Route: direct verification; tests/checks may use fresh workers where useful.
- Other final gates passed on the first candidate: changed-PHP `php -l`; container lint; YAML lint (441 files); PHPStan max; PHP-CS-Fixer (fixed 0/1,945); Deptrac (0 violations, skips, uncovered, warnings, or errors); `git diff --check`.
- Initial full-coverage candidate failed: 5,831 tests / 30,098 assertions passed, but line coverage was 99.64% (20,459/20,531; 72 uncovered). HLP-01R added focused tests for those HELP branches; rerun full coverage once on the corrected final candidate.
- Progress: other final gates passed; HLP-01R focused checks are complete; corrected full-coverage gate remains.

## Current progress and next step

- Branch created from clean `main`; HLP-01 is complete and committed as the first local chain slice (`d2089f4d`).
- Delivery chain strategy selected: `feature-branch-chain`; PRs/remote actions remain unauthorized.
- The design pack and current HELP architecture were reviewed; the direct-help visibility choice was confirmed by the user.
- Next: commit HLP-01R, complete final verification including one corrected full-coverage run, then record the final outcome.

## Relevant files

- `docs/ares-help-design-pack/ares-help-redesign-plan.md` — behavior and acceptance source.
- `src/{NickServ,ChanServ,MemoServ,OperServ}/Adapter/In/Irc/Help/UnifiedHelpFormatter.php` — service-owned renderers.
- `translations/{nickserv,chanserv,memoserv,operserv}.*.yaml` — localized help catalogs.
- `.agents/services.md` — contributor guidance for service commands and bots.
- `config/services.yaml`, `CHANGELOG.md`, and CTCP/service-version tests — visible release version.
