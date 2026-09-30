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
- Local feature-branch chain slices: 1) HLP-01 HELP code, tests, and all translations — `d2089f4d`; 2) HLP-02 agent guidance — `47d9bef1`; 3) HLP-03 version/changelog/assertions — `68519c31`; 4) HLP-01R focused coverage follow-up — `fa6bbb04`; 5) HLP-04 final verification record — `55791ba2`. The cached `feature-branch-chain` strategy still applies; no PRs or remote actions are authorized.
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
- Checks passed: focused four-service HELP tests plus shared translation alignment (`66 tests, 983 assertions`); final `php -l` on all 31 changed PHP files; `php bin/console lint:yaml . --exclude vendor/ --parse-tags` (441 files); independent translation lint (84 files); PHPStan max; `git diff --check`. Final PHP-CS-Fixer passed and formatted two HLP-01R test files (2/1,945 files).
- Forecast: actual HLP-01 diff is 2,184 authored additions/deletions across 79 files; selected strategy is `feature-branch-chain`.
- Progress: implemented, focused checks passed, and committed as `d2089f4d` (`feat(help): redesign service help across locales`). RDD outcome: disabled/unmanaged.

### HLP-01R — Cover remaining HELP presentation branches

- [x] Add focused tests for HELP production branches found uncovered by the first final coverage run, without changing command authorization or user-visible behavior.
- Route: **delegated direct**. Trigger evidence: read-only Clover mapping identified branches across four formatters, five context-adapter tests, and ChanServ's command HelpCommand test; changes span 2+ non-trivial files.
- Mapped cases: ungrouped visible-command fallback in all four formatter tests; synthetic admin/subgroup and IRCOP cases for MemoServ/OperServ; context-adapter visibility/group forwarding cases; distinct unknown `HELP MISSING` case in ChanServ. Keep the known-but-denied command behavior test unchanged.
- Checks passed: focused tests across the 10 mapped files (`98 tests, 225 assertions`); `php -l` on all 10 changed test files; `git diff --check`. The full coverage rerun belongs to HLP-04.
- Progress: test-only cases implemented, verified, and committed as `fa6bbb04` (`test(help): cover remaining formatter branches`); existing known-but-denied behavior remains unchanged. RDD outcome: disabled/unmanaged.

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

- [x] Run `php -l` on changed PHP files, `php bin/console lint:container`, `php bin/console lint:yaml . --exclude vendor/ --parse-tags`, PHPStan max, PHP-CS-Fixer (inspect resulting diff), configured architecture gate, and the final `./scripts/check-coverage.sh 100 --issues` after HLP-01R.
- [x] Record each result, failed/skipped/unavailable checks, work-unit commit IDs, and final next step here; update the Engram mirror after each task.
- Route: direct verification; tests/checks may use fresh workers where useful.
- Final corrected candidate passed: `php -l` on all 31 changed PHP files; `php bin/console lint:container`; YAML lint (441 files); PHPStan max; PHP-CS-Fixer (fixed 2/1,945 files, both HLP-01R test docblocks); Deptrac (0 violations, skipped violations, uncovered, warnings, or errors; 4,410 allowed); `git diff --check`.
- Final corrected full-coverage gate passed: `./scripts/check-coverage.sh 100 --issues` — `5,841 tests, 30,128 assertions`; classes 100% (890/890), methods 100% (4,515/4,515), lines 100% (20,531/20,531); no reported issues.
- Initial candidate history: the first run passed 5,831 tests / 30,098 assertions but failed at 99.64% (20,459/20,531; 72 HELP lines uncovered). After HLP-01R test-only coverage corrections, the corrected candidate passed the full 100% gate above.
- Progress: all final checks passed and the verification record was committed as `55791ba2` (`docs(help): record final redesign verification`); no failed, skipped, or unavailable checks remain. RDD outcome: disabled/unmanaged. No push, PR, or tag was requested or performed.

## Current progress and next step

- Branch created from clean `main`; HLP-01, HLP-01R, HLP-02, HLP-03, and HLP-04 are complete, with their local work-unit commit identities recorded above.
- Delivery chain strategy selected: `feature-branch-chain`; PRs/remote actions remain unauthorized.
- The design pack and current HELP architecture were reviewed; the direct-help visibility choice was confirmed by the user.
- Original redesign scope is complete. A follow-up color-correction scope is now authorized below; no remote delivery, push, or PR is authorized.

## Relevant files

- `docs/ares-help-design-pack/ares-help-redesign-plan.md` — behavior and acceptance source.
- `src/{NickServ,ChanServ,MemoServ,OperServ}/Adapter/In/Irc/Help/UnifiedHelpFormatter.php` — service-owned renderers.
- `translations/{nickserv,chanserv,memoserv,operserv}.*.yaml` — localized help catalogs.
- `.agents/services.md` — contributor guidance for service commands and bots.
- `config/services.yaml`, `CHANGELOG.md`, and CTCP/service-version tests — visible release version.

## Authorized follow-up: HELP color update

### Objective

Apply `plans/ares-help-color-update-plan.md` using `plans/irc-help-style.md` as the design rule: make HELP structure consistently readable on light and dark IRC client backgrounds for NickServ, ChanServ, MemoServ, OperServ, and future commands/services.

### Problem and why

The completed HELP redesign emits structural mIRC colors `11` and `12`, and its visual palette is partly embedded in translated layout strings. The new plan replaces those choices with semantic roles and resets that avoid formatting bleed, while preserving localized text and existing behavior.

### Authorized scope

- HELP presentation only: service headers, sections/groups, command/subcommand rows, navigational hints, warnings, admin sections, separators, and HELP-specific error/help text where its existing color is structural.
- Four service-owned HELP formatters, focused regression/translation checks, all 14 locales for the four service catalogs, and agent guidance required by the plan.
- New `.agents/irc-help-style.md` and the reference/checklist update in `.agents/services.md`.
- No command execution, business logic, authorization policy, persistence, IRC protocol behavior, non-HELP response redesign, design-plan edits, remote operations, push, PR, or tag.

### Constraints and decisions

- The new color plan supersedes earlier palette choices for HELP only; retain current groups, command inventory, translations' natural wording, dynamic behavior, and permission filtering.
- Use mIRC decimal color codes: title/normal section `06`, command `03`, marker `10`, admin/error `04`, warning `07`, separator `14`; descriptions remain default foreground. Bold/reset controls do not mean color index `02`.
- Do not introduce structural colors `00`, `01`, `02`, `08`, `09`, `11`, `12`, `13`, or `15`.
- Do not add classes or a shared style helper. Keep formatting in the existing service HELP formatters, but keep marker glyphs and mIRC color markup out of command PHP; place localized structural markup in the existing HELP translation entries and guard its palette in tests. Keep formatter and visibility policy service-owned.
- Complete the correction in all four service bots and all 14 supported languages; a NickServ-only implementation is incomplete.
- TDD is disabled by the user's explicit HELP-redesign choice. Run ordinary focused functional checks with `./vendor/bin/phpunit --no-coverage --display-all-issues`.
- Receipt-driven development remains disabled by global user preference: report `disabled/unmanaged`; do not assess, review, or toggle it.
- Reuse the already selected `feature-branch-chain` strategy for this HELP feature on `feat/ares-help-redesign`; no remote delivery is authorized. Forecast approximately 1,300 authored changed lines (generated files excluded); track actual additions plus deletions from task commits and record slice boundaries.

### Acceptance criteria

- All four services use the same documented color semantics for HELP structure; descriptions remain uncolored by structural palette, and each styled fragment resets formatting.
- HELP general, command, and supported subcommand output remains translated in all 14 locales, with no forbidden structural colors.
- Existing command grouping, metadata, sender visibility, Root/IRCop permission behavior, and command execution are unchanged.
- Style behavior, formatter integration, all-locale placeholders/palette, and representative HELP paths have regression coverage.
- `.agents/irc-help-style.md` exists and `.agents/services.md` makes it mandatory for new commands/services.
- All applicable project quality gates pass. Manual mIRC light/dark verification is recorded honestly; if no local client is available, it remains explicitly pending rather than claimed.

### Tasks and progress

#### HLP-05 — NickServ migration without a new style class

- [x] Remove the newly introduced `IrcHelpStyle` class and its unit test; place HELP markers/color markup in existing localized HELP translation entries, and migrate NickServ formatter/tests plus all 14 locale catalogs without changing visibility or behavior.
- [x] Rewrite `.agents/irc-help-style.md` to document the palette without a helper class, and make `.agents/services.md` reference that rule for all bots.
- Route: **delegated direct**. Trigger evidence: implementation changes multiple non-trivial PHP, test, and locale files; the style API is shared with the remaining independent service migrations.
- Implementation constraint: no additional class; no marker glyph or color-selection helper call in command PHP. Colors/markers belong in existing localized HELP resources, while code retains only layout and presentation branching.
- Checks passed on the revised implementation: NickServ formatter/command/context/alignment tests (`43 tests, 1,862 assertions`); `php -l` on the four changed NickServ PHP files; `git diff --check`.
- Cross-service regression outcome: combined NickServ/alignment/catalog run (`47 tests, 1,949 assertions`) has one failure in `TranslationCatalogTest::everyKeyReferencedByCodeIsDefined`: ChanServ formatter references `help.header`, missing from the ChanServ catalog. HLP-06 is reopened to fix and reverify that translation contract; the NickServ tests in the run passed.
- Verification history: an earlier NickServ run found missing locale keys and stale command-test expectations; those were repaired and revised NickServ checks now pass.
- Runtime harness: **N/A** — deterministic presentation output is covered by PHPUnit; no live IRC session was authorized or required. Manual mIRC light/dark visual confirmation remains pending.
- Rollback boundary: revert the revised NickServ HELP presentation/tests and 14 catalogs, the palette/catalog regression check, and the adjusted style guidance; do not retain a new shared helper class.
- Authored diff: 1,095 additions plus deletions in commit `65e4ce57` (including this tracker update); the slice includes NickServ's 14 catalogs, formatter/command/tests, and both style-guide changes. Cached `feature-branch-chain` strategy applies.
- Progress: revised implementation and focused checks are complete; the helper class and unit test are removed, and NickServ uses its 14 localized catalogs. Work-unit committed as `65e4ce57` (`fix(nickserv): localize HELP color markup`). The global catalog failure is tracked separately under HLP-06.
- [x] Fix final PHPStan max findings in NickServ HELP tests: validate string-keyed translation parameter maps, safely render mixed parameter values, and safely access the final raw reply; preserve behavior and all-locale coverage.
- PHPStan max now passes with zero findings across `src/` and `tests/`; relevant fixes are in `tests/NickServ/Adapter/In/Irc/Command/HelpCommandTest.php` and `Help/UnifiedHelpFormatterTest.php`.

#### HLP-06 — ChanServ color migration

- [x] Migrate ChanServ HELP formatter, tests, and all 14 locale catalogs to the canonical style using existing translation entries, with no new style class; preserve all mode/permission-dependent visibility.
- Route: **delegated direct**. Trigger evidence: writer trigger applies across formatter, test, and 14 non-trivial locale files.
- Checks passed on the corrected migration: focused ChanServ formatter/command plus `TranslationCatalogTest` (`34 tests, 498 assertions`), with palette and rendered-output assertions across all 14 locales; `php -l` on both changed production files and the changed formatter test; targeted PHP-CS-Fixer; `git diff --check`.
- Verification history: the first catalog run found missing `help.header`; adding that key exposed stale legacy `11`/`12` styles in existing HELP values, so the task stayed open until all 14 help subtrees were migrated. An initial new test fixture also had a command outside its groups; corrected before the final passing run.
- Runtime harness: **N/A** — translated formatter output is exercised directly by PHPUnit; no live IRC session was authorized or required.
- Authored diff: 352 additions plus deletions across the ChanServ formatter/command, test, and 14 locale catalogs; cached `feature-branch-chain` strategy applies.
- Progress: implementation and checks complete; work-unit committed as `3855dc9a` (`fix(chanserv): localize HELP color markup`). Manual IRC-client light/dark visual verification remains pending.
- [x] Fix final PHPStan findings in `src/ChanServ/Adapter/In/Irc/Command/HelpCommand.php` (empty-array return type) and `tests/ChanServ/Adapter/In/Irc/Help/UnifiedHelpFormatterTest.php` (validated YAML catalog and precise nested map/string/index types), without changing output.
- PHPStan max now passes with zero findings; the full suite also identified one previously uncovered `getHelpParams()` return line, tracked as a focused coverage follow-up in HLP-09.

#### HLP-07 — MemoServ color migration

- [x] Migrate MemoServ HELP formatter, tests, and all 14 locale catalogs to the canonical style using existing translation entries, with no new style class; preserve existing command visibility.
- Route: **delegated direct**. Trigger evidence: writer trigger applies across formatter, test, and 14 non-trivial locale files.
- Checks passed: focused MemoServ HELP formatter/command tests (`13 tests, 700 assertions`), including translated rendering and canonical-color checks across all 14 locales; `php -l` on the modified formatter and both changed tests; `git diff --check`.
- Runtime harness: **N/A** — translated formatter output is exercised directly by PHPUnit; no live IRC session was authorized or required. Manual IRC client verification remains pending.
- Authored diff: 486 additions plus deletions across the MemoServ formatter, tests, and 14 locale catalogs; cached `feature-branch-chain` strategy applies.
- Progress: implementation and checks complete; work-unit committed as `2522d912` (`fix(memoserv): localize HELP color markup`).
- [x] Add precise array generics for the recursive catalog flattener and optional translations in `tests/MemoServ/Adapter/In/Irc/Help/UnifiedHelpFormatterTest.php`; validate decoded catalogs as string-keyed maps and preserve all 14 locale assertions.
- PHPStan max now passes with zero findings; all MemoServ formatting and locale assertions remain passing.

#### HLP-08 — OperServ color migration and palette regression guard

- [x] Migrate OperServ HELP formatter, tests, and all 14 locale catalogs using existing translation entries and no new style class; add/extend an all-service/all-locale check preventing forbidden structural colors.
- Route: **delegated direct**. Trigger evidence: formatter, regression test, and 14 locale files are non-trivial; code contracts are frozen by HLP-05.
- Checks passed: focused OperServ HELP formatter/command tests (`12 tests, 1,888 assertions`); combined all-service HELP, translation alignment, and palette-guard suite (`104 tests, 4,961 assertions`); `php -l` on every changed PHP file; `git diff --check`.
- Palette guard: `TranslationCatalogTest` inspects all 56 service catalogs across all 14 locales, allowing only structural colors `03`, `04`, `06`, `07`, `10`, and `14`; the same test ensures HELP commands contain no style helper, mIRC color controls, or marker glyphs.
- Authored diff: 577 additions plus deletions across OperServ formatter/tests, shared translation regression coverage, and 14 locale catalogs; cached `feature-branch-chain` strategy applies.
- Progress: implementation and focused checks complete; work-unit committed as `6964e310` (`fix(operserv): localize HELP color markup`). Manual IRC-client light/dark visual verification remains pending.
- [x] Refine regex-capture and file-read types in `tests/Shared/Translations/TranslationCatalogTest.php` to resolve its final PHPStan max findings without suppressions; preserve the all-service/all-locale palette guard.
- PHPStan max now passes with zero findings; the all-service/all-locale palette guard remains passing.

#### HLP-09 — Final verification and delivery record

- [x] Run and record final static, functional, and architecture checks; record coverage outcome and manual visual validation honestly.
- [x] Add a focused ChanServ HELP command test for the empty `getHelpParams()` result, then rerun coverage against the corrected candidate.
- Route: **direct verification**; testing/build actors may be used without changing the implementation route.
- Checks: project final verification order in `AGENTS.md`, including the single full coverage run and configured architecture gate.
- Passed on the corrected candidate: `php -l` on all 10 changed PHP files; `php bin/console lint:container`; YAML lint (441 files); PHPStan max (zero findings); PHP-CS-Fixer (8 of 1,945 files fixed and inspected, then 0 files on the clean rerun); focused all-service HELP/catalog suite (`89 tests, 6,050 assertions`); Deptrac (0 violations, skipped violations, uncovered, warnings, or errors; 4,410 allowed); `git diff --check`.
- Coverage history: the first run completed `5,851 tests, 35,937 assertions`, but exposed one uncovered ChanServ `HelpCommand::getHelpParams()` return statement. Added a focused empty-parameter test and aligned its return-map PHPDoc with the help-parameter contract. The corrected `./scripts/check-coverage.sh 100 --issues` passed: `5,852 tests`, 100% classes (890/890), methods (4,518/4,518), and lines (20,580/20,580); no issues reported. The earlier 99.99% result is retained here as verification history, not an outstanding failure.
- PHPStan history: the initial max analysis identified 26 findings across six paths; corrections in HLP-05 through HLP-08 removed the missing array generics, validated translation maps, safe indexes, and redundant assertions without suppressions. The exact full analysis now passes with zero findings.
- Progress: correction and final verification work unit committed as `a693eb8a` (`fix(help): close color migration verification gaps`). All automated project gates pass. Manual IRC-client light/dark visual verification remains pending because no local live IRC client was available; no remote operations were authorized or performed.

### Delivery and recovery state

- Feature identity remains `ares-help-redesign`; HLP-01 through HLP-04 above are historical completed tasks, and HLP-05 through HLP-09 are the newly authorized follow-up.
- Current branch is `feat/ares-help-redesign`; HLP-05, HLP-06, HLP-07, and HLP-08 are committed as `65e4ce57`, `3855dc9a`, `2522d912`, and `6964e310`; final correction/verification is committed as `a693eb8a`. TDD: disabled, source: prior explicit user choice recorded in the existing tracker, runner: `./vendor/bin/phpunit --no-coverage --display-all-issues`.
- Forecast was under by more than 400 authored changes. Follow-up work-unit diffs total 2,798 additions plus deletions through `a693eb8a` (generated files excluded); cached `feature-branch-chain` strategy applies. No push/PR/tag or other remote operation.
- Current next step: no further code work remains. Manual IRC-client light/dark visual verification is pending client availability; remote delivery remains the user's decision.

## Authorized GitHub delivery — 2026-09-30

### Authorization and delivery constraints

- The user explicitly authorized creating an issue, preparing PRs, merging the completed HELP work to `main`, and publishing a release, and confirmed the current `gh` session for `davidlig/ares-irc-services`.
- The requested release tag is exactly `2.3.0` (without a `v` prefix).
- The user confirmed that the merge to `main` may trigger the configured production deployment.
- The user accepted `size:exception` for indivisible work units that exceed the chained-PR 400-line review budget. Do not split coherent code/translation/test units just to meet the budget.
- Keep the previously selected `feature-branch-chain` strategy. Use an issue-approved, exactly-one-`type:*`-label PR chain; apply `size:exception` to each applicable oversized PR. Never bypass failing CI or required protections.
- GitHub discovery on 2026-09-30: issues and blank issues are enabled; the default branch is `main`; no repository issue/PR templates are present; labels include `status:approved`, `type:feature`, and `size:exception`. No matching HELP redesign issue/PR or release/tag `2.3.0` was found. The issue fallback body must receive a pre-submission privacy review before publication.
- CI runs the production deploy job on pushes to `main` after test and migration jobs pass. RDD is disabled/unmanaged; do not start review flows.

### Delivery tasks

#### DLV-01 — Create the approved issue

- [x] Publish one structured feature issue for the all-service HELP redesign/color update and v2.3.0 release, applying the existing `status:approved` label with the user's explicit approval.
- Route: **direct inline**. Trigger evidence: one public GitHub issue artifact; repository/template/label discovery is complete and no issue template applies.
- Acceptance: no duplicate; body reflects only verified scope/evidence; issue link and approval label recorded below.
- Completed: issue [#13](https://github.com/davidlig/ares-irc-services/issues/13), “Redesign and localize HELP across all services (v2.3.0)”; labels `enhancement` and `status:approved`. The body passed the required pre-submission privacy scan and distinguishes pending manual IRC-client visual confirmation from automated checks.

#### DLV-02 — Prepare the feature-branch PR chain

- [x] Create the draft tracker PR and chained child PRs from the existing work-unit boundaries; link the approved issue in every PR and add exactly one `type:feature` label.
- [x] Add `size:exception` only to oversized indivisible work units/tracker integration PR as accepted by the user; each child body records chain position, immediate base/dependency, scope, and verification evidence.
- Route: **delegated direct**. Trigger evidence: the scoped read-only PR-history and GitHub-settings mapping was delegated before preparing remote branch/PR writes; the existing work units and immediate-parent boundaries are now verified.
- Acceptance: focused diffs where possible; preserve work-unit boundaries, do not mix feature-branch-chain with another chain strategy, and observe applicable checks.
- Chain layout: create `feat/ares-help-redesign-tracker` at `d2089f4d` (core HELP redesign, 2,273 authored changed lines; indivisible and user-approved `size:exception`) and open it as a draft PR to `main`. Six child PRs follow the exact original linear history, each targeting the preceding source branch: `d2089f4d..55d1f306` (343), `55d1f306..65e4ce57` (NickServ, 1,095; exception), `65e4ce57..2522d912` (MemoServ, 496; exception), `2522d912..3855dc9a` (ChanServ, 365), `3855dc9a..6964e310` (OperServ, 593; exception), and `6964e310..d88a71fc` (verification/issue evidence, 321). Full feature diff at `d88a71fc` is 4,314 authored changed lines (+3,382/−932); later commits revise earlier task/translation lines, so slice sizes are not additive.
- Merge topology: repository settings permit merge commits, squash, and rebase, but not auto-merge; use merge commits for children so original commit ancestry remains available. No `main` branch protection or repository ruleset was visible, so explicitly wait for and check the final tracker PR's CI rather than relying on server enforcement.
- Verification caveat: `.github/workflows/ci.yml` only runs CI on PRs targeting `main`; chained child PRs to feature branches will not receive Actions runs. Their documented focused tests remain the child-slice evidence, and the final tracker PR to `main` must run and pass the applicable full CI before merge.
- Published PRs: draft tracker [#14](https://github.com/davidlig/ares-irc-services/pull/14) (2,273; `type:feature`, `size:exception`); [#15](https://github.com/davidlig/ares-irc-services/pull/15) release readiness (343; `type:feature`); [#16](https://github.com/davidlig/ares-irc-services/pull/16) NickServ (1,095; exception); [#17](https://github.com/davidlig/ares-irc-services/pull/17) MemoServ (496; exception); [#18](https://github.com/davidlig/ares-irc-services/pull/18) ChanServ (365); [#19](https://github.com/davidlig/ares-irc-services/pull/19) OperServ (593; exception); [#20](https://github.com/davidlig/ares-irc-services/pull/20) final verification (321). Each is linked to approved issue #13 and has exactly one `type:feature` label; oversized indivisible PRs carry the approved exception label.
- Initial tracker CI result: PHP/coverage job failed on the incomplete core-only boundary at 99.64% lines (20,459/20,531; 5,831 tests); all four migration matrix jobs passed and deployment was skipped. Coverage tests/corrections are included in children #15 and #20. This is not the final full-chain CI result; the tracker remains draft and must be rerun after integration.

#### DLV-03 — Integrate the chain and merge to main

- [ ] Merge child PRs in chain order, then merge the tracker PR to `main` only after required checks pass; observe the resulting production deployment and record its result.
- Route: **direct delivery**. Trigger evidence: user explicitly authorized merge and its production-deploy side effect.
- Acceptance: no bypass; main contains the full approved chain; deployment status recorded accurately.

#### DLV-04 — Publish release/tag 2.3.0

- [ ] After the verified main merge, create GitHub tag/release `2.3.0` using the prepared `CHANGELOG.md` entry; record tag target and release URL.
- Route: **direct delivery**. Trigger evidence: explicit user-specified tag and release authorization.
- Acceptance: tag points to the merged main commit; release notes match the v2.3.0 changelog; remote artifact and URL recorded.

### Delivery progress

- Current status: DLV-01 is complete; issue #13 is published and approved. DLV-02 is complete: tracker #14 and child PRs #15–#20 are open, linked to #13, correctly labeled, and match the selected branch chain. Child PRs have no Actions checks because their bases are feature branches. Tracker #14's first, incomplete-boundary CI failed at 99.64% coverage as recorded above; it has not been merged.
- Next step: integrate the child PRs in order into the tracker, rerun and require passing full CI on #14, then merge to `main`, observe deployment, and publish tag/release `2.3.0`.
