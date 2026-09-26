# Ares IRC Services v2.1.2 release

## Objective and authorization

Prepare and publish Ares IRC Services v2.1.2, including the authorized ChanServ PRIVATE/INFO changes currently on `feat/chanserv-private-info`. Update the displayed application version, its CTCP VERSION tests, and the newest changelog entry; verify the release candidate; create reviewable work-unit commits; publish the v2.1.2 GitHub release; then create the authorized feature-branch-chain PRs and merge them to `main` when repository gates permit.

Authorized scope: local release edits/commits and push/tag/release publication through `gh` to `davidlig/ares-irc-services`. On 2026-09-26 the user additionally authorized creating the feature-branch-chain PR(s) and merging to `main` via `gh`. Do not merge until required approved-issue linkage and automated checks are satisfied.

## Scope and constraints

- Current displayed version is `services.version` in `config/services.yaml`; both `CtcpVersionResponderTest` and `CtcpHandlerTest` assert the current version string.
- Add a newest-first `[2.1.2] - 2026-09-26` entry to `CHANGELOG.md` covering enabled-only INFO options, INFO row order, `SET PRIVATE`, privacy authorization, and the database migration upgrade note.
- Keep ChanServ behavior/tests/translations/migration together in behavior work units; release metadata is its own slice.
- The existing feature tree is already on `feat/chanserv-private-info`; do not reset, rebase, or discard its uncommitted work.
- Use local repository history and `gh` as explicitly directed; do not inspect or reuse other remote sessions/credentials.
- PR/merge authority is explicit. User authorized and labels were created: `status:approved` applied to issue #5; `type:feature` is available for each PR. Continue to wait for automated checks before merging.

## Route, tests, and delivery

- Route: delegated direct. Mapping trigger fired because understanding spans config/services.yaml, two CTCP test files, CHANGELOG.md, and release/tag history; one read-only mapping worker completed. Writer trigger applies to the version/changelog/test edits across multiple non-trivial files; delegate one bounded writer after this task document and mirror are saved.
- Effective TDD: enabled, test-first, from the user's earlier “el que quieras” choice. Runner: `./vendor/bin/phpunit --no-coverage --display-all-issues tests/Irc/Adapter/In/Event/CtcpVersionResponderTest.php tests/Irc/Adapter/In/Event/CtcpHandlerTest.php`; change expected response versions first and observe RED before changing constructor fixtures/config, then GREEN.
- RDD: disabled/unmanaged (verified during the ChanServ implementation).
- Forecast: approximately 800 authored changed lines including ChanServ and release metadata, generated files excluded; therefore over the ~400-line delivery budget. Delivery strategy: `feature-branch-chain`, explicitly selected by the user. Work-unit commits: (1) persisted PRIVATE/SET; (2) INFO presentation/refactor; (3) PRIVATE INFO authorization; (4) release metadata. Issue #5 is approved and both required labels now exist. PR branches must be rebuilt from `main` because the published feature branch contains unrelated deployment commits; do not rewrite the existing release/tag history. `.github/workflows/ci.yml` only triggers PR CI when the base is `main`, so child-chain PRs would receive no automated checks without an authorized workflow change.
- Checks: focused version/ChanServ tests, `php bin/console lint:container`, `php bin/console lint:yaml . --exclude vendor/ --parse-tags`, PHPStan max, PHP-CS-Fixer, configured architecture gate, final `./scripts/check-coverage.sh 100 --issues`, and `git diff --check`. Run full coverage once after all source/config changes.

## Tasks

- [x] **R1 — Set v2.1.2 metadata and changelog.** Update the visible service version, version assertions and release notes. Route: delegated direct. Trigger evidence: release mapping covered 4+ files; writer trigger applies across the version config, changelog and test files.
- [x] **R2 — Verify the release candidate.** Run focused RED/GREEN version tests and all applicable final gates, including 100% full coverage after all changes. Route: parent coordination/checks.
- [x] **R3 — Create feature-branch-chain work-unit commits.** Create the four planned review slices as cohesive work-unit commits, keep tests/docs with each unit, record IDs and authored line counts, and update the ChanServ task mirror with commit evidence. Route: parent coordination; strategy selected by user.
- [x] **R4 — Publish v2.1.2 with gh.** Push the authorized feature branch and release tag as required, create GitHub release `v2.1.2` on `davidlig/ares-irc-services`, and record the resulting URL/commit. PR/merge were separately authorized later and are tracked by R5.
- [x] **R5 — Integrate the release PR and deploy main.** Completed under the final delivery amendment below: PR #6 merged after CI passed; main workflow 36251701852 passed all quality/migration jobs and deployed exact SHA dd6d7a1460d3dd0d42dad7b30884ee249f497dec successfully. Earlier chain reconstruction was superseded by the final direct integration request.

## Acceptance criteria

- Runtime-visible version and CTCP VERSION responses report `v2.1.2`.
- Changelog accurately summarizes ChanServ changes and warns that migration `Version20260926000002` must be applied.
- Focused and final quality checks pass; full coverage is 100% with no skipped/incomplete/risky/deprecated tests.
- Work-unit commits use English Conventional Commit messages, keep relevant tests/docs with behavior, and have recorded verification and rollback boundaries.
- A GitHub release `v2.1.2` exists on the authorized repository and points to the verified release commit; no unrequested PR or merge is created.
- Required PR chain links an approved issue, carries exactly one matching `type:*` label per PR, passes automated checks, and is merged to `main` in chain order.

## Progress, evidence, and next step

- Feature branch: `feat/chanserv-private-info`, based on local `feat/main-autodeploy` at `adbeb688a0a66fec1d33c10d780553038a99ae89`; four feature/release work-unit commits are complete, and v2.1.2 is published.
- Read-only mapping confirmed `config/services.yaml` contains the visible `v2.1.1` value; version expectations occur in `tests/Irc/Adapter/In/Event/CtcpVersionResponderTest.php` and `tests/Irc/Adapter/In/Event/CtcpHandlerTest.php`. `CHANGELOG.md` is manually maintained and newest-first. No release-generation script/workflow is present; composer metadata has no app version.
- Existing ChanServ behavior is verified: 5,717 tests / 28,296 assertions, 100.00% lines (20,111/20,111); container/YAML lint, PHPStan max, PHP-CS-Fixer, Deptrac and diff check passed. Re-run applicable checks after release metadata changes.
- R1 complete: updated `services.version`, both CTCP test files and the manual changelog. Test-first RED produced the six expected version mismatches across 22 tests; GREEN passed 22 tests / 828 assertions after test fixtures/config were updated. `git diff --check` passed. The tests instantiate the responder directly, so they characterize v2.1.2 but do not read `config/services.yaml`.
- R2 complete: focused ChanServ + CTCP version suite passed 1,464 tests / 5,942 assertions; syntax checks passed for all changed PHP files; container lint, all 441 YAML files, PHPStan max, PHP-CS-Fixer (0 files changed), Deptrac (0 violations), and `git diff --check` passed. Final coverage passed 5,717 tests / 28,296 assertions and 100.00% (20,111/20,111 lines).
- R3 slice 1 committed as `304a40cc` (`feat(chanserv): persist PRIVATE channel setting`): 24 files, 348 additions / 17 deletions (365 authored changed lines). Focused verification: `./vendor/bin/phpunit --no-coverage --display-all-issues tests/ChanServ/Adapter/In/Irc/Command/SetCommandTest.php tests/ChanServ/Adapter/In/Irc/Command/SetPrivateHandlerTest.php tests/ChanServ/Domain/Entity/RegisteredChannelTest.php` — 59 tests / 140 assertions passed; staged diff check passed. Runtime harness: N/A; this slice is fully exercised through unit/adapter tests without an external IRCd boundary. Rollback boundary: revert this commit to remove persisted PRIVATE setting/SET support, migration and its locale/help strings as a unit.
- R3 slice 2 committed as `c1a64976` (`feat(chanserv): refine INFO channel details`): 16 files, 275 additions / 75 deletions (350 authored changed lines). Focused verification: `InfoCommandTest.php` passed 48 tests / 71 assertions; staged diff check passed. Runtime harness: N/A; IRC adapter behavior is exercised with in-memory context/notifier tests. Rollback boundary: revert this commit to restore prior INFO option presentation/order and the old mixed `execute()` implementation; PRIVATE access work is a separate following commit.
- R3 slice 3 committed as `704d49b6` (`feat(chanserv): enforce private INFO access`): 5 files, 168 additions / 3 deletions (171 authored changed lines). Focused verification: `InfoCommandTest.php` passed 48 tests / 71 assertions; staged diff check passed. Runtime harness: N/A; access decisions are covered by application/adapter tests without an external IRCd. Rollback boundary: revert this commit to remove the PRIVATE INFO authorization decision while preserving the channel setting and public INFO presentation.
- R3 slice 4 committed as `bc16c8a3` (`chore(release): prepare v2.1.2 metadata`): 6 files, 149 additions / 11 deletions (160 authored changed lines), including both recovery documents. Focused verification: both CTCP VERSION test files passed 22 tests / 828 assertions; staged diff check passed. Runtime harness: N/A; version response is unit-tested and container lint verified service configuration. Rollback boundary: revert this commit to return visible version/changelog/test literals to v2.1.1 and remove release task records.
- Four local work-unit commits are complete in order: `304a40cc` → `c1a64976` → `704d49b6` → `bc16c8a3`. No PR/merge was created before the user's latest authorization.
- R4 complete: annotated tag `v2.1.2` (object `ce0d6ec7ed8283d1a0ecf2d61b6ded8c9039f227`) points to release commit `bc16c8a31c127f6e0e47dd52f124eab8c88f9604`. Pushed `feat/chanserv-private-info` and tag to the authorized GitHub repo via the `gh` credential helper. Created and verified non-draft, non-prerelease release at https://github.com/davidlig/ares-irc-services/releases/tag/v2.1.2; remote ref check confirmed tag peel and branch both resolve to `bc16c8a31c127f6e0e47dd52f124eab8c88f9604`. No PR or merge was created.
- Latest user requests (2026-09-26): create the PR chain, merge, create issue, and add/apply approval labels. Issue [#5](https://github.com/davidlig/ares-irc-services/issues/5) exists with a reviewed structured body. User authorized and we created `status:approved` and `type:feature`; applied `status:approved` to #5 and verified it. `gh pr list` found no existing PR. Git ancestry inspection showed `feat/chanserv-private-info` contains unrelated deployment commits after `main` and cannot be used directly as a clean PR tracker. Build clean chain branches from `main` with cherry-picked work units; preserve the existing published branch and tag. The PR CI trigger is filtered to base `main`, so child PRs need an explicitly authorized workflow trigger update. The workflow's push-to-main job deploys to the production self-hosted runner after test/migration success; obtain explicit authorization for that deployment before final merge. The release tag remains pinned to the release metadata commit.

## Relevant files

- `config/services.yaml` — version injected into the runtime CTCP VERSION response.
- `tests/Irc/Adapter/In/Event/CtcpVersionResponderTest.php` — responder version expectation.
- `tests/Irc/Adapter/In/Event/CtcpHandlerTest.php` — handler version expectations.
- `CHANGELOG.md` — human-maintained release notes.
- `odd/tasks/chanserv-private-info.md` — implementation scope and verification/commit recovery record.

## Final delivery amendment — 2026-09-26

The user now explicitly requests deployment to main through the PR without further procedural confirmation. Deliver the existing published, verified branch in one integration PR to main rather than rebuilding a chain that omits deployment prerequisites. This supersedes the earlier proposed clean-branch delivery: origin/main has no deployment job or scripts, so the existing main-autodeploy ancestry is required for the requested outcome, not unrelated scope. Preserve all existing commits and v2.1.2 tag. No CI trigger changes are necessary: the integration PR targets main and runs the existing automated gates. This is an oversized integration delivery (56 files; previously measured 2,706 authored changed lines), retaining reviewable ChanServ work-unit commits. Production deployment after merge is explicitly authorized. R5 completed after CI, merge and deployment were observed.

## Final delivery evidence

- Infrastructure regression suite rerun: 54 Python tests passed; git diff --check passed.
- PR https://github.com/davidlig/ares-irc-services/pull/6 links approved issue #5 and carries type:feature. Head 5071de9dfa8c441de10670c4f9e901efc6a4673a passed PR CI run 36251401588: PHP quality/tests and all three migration jobs. Deploy correctly skipped on pull_request.
- Merged with gh --merge and exact head guard on 2026-09-26T15:22:16Z; main merge SHA dd6d7a1460d3dd0d42dad7b30884ee249f497dec.
- Main run https://github.com/davidlig/ares-irc-services/actions/runs/36251701852 completed successfully, including PHP quality/tests, SQLite/MariaDB/PostgreSQL migrations, and Deploy Ares IRC Services (39 seconds). No bypass/admin merge or CI workflow edits.
- Publication tag v2.1.2 remains unchanged. Closing evidence is recorded on the retained feature branch to avoid an unnecessary second production deployment for documentation alone.
