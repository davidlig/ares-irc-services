# Contribution double approval

## Objective

Admit only pull requests backed by maintainer-approved issues and block integration
until the independent review and existing CI requirements are satisfied.

## Authorized scope and constraints

- Canonical label: `approved`; historical `status:approved` grants no authorization.
- Validate every closing/manual linked issue in this repository, including provenance.
- Approval must precede the latest opening/reopening; corrected PRs may be reopened.
- Invalidate the required check, post one friendly bot comment, then close invalid PRs.
- Revalidate description edits, commits, approval revocation and issue/link changes.
- Missing code review leaves an otherwise admitted PR open but blocks merge.
- Exempt only davidlig-authored PRs from independent review, not issue approval or CI.
- Every feature must remain within its own approved issue; maintainers review scope.
- Use a dedicated expected-source GitHub App check, not an impersonable Actions job.
- Never execute untrusted PR code in privileged workflows; preserve deployment isolation.
- Copilot and AI review are out of scope. No remote writes, push, PR creation or merge.
- External App/secret/protection activation remains documented and pending authorization.
- Metadata reconciliation is asynchronous; API/Actions outages cannot guarantee immediacy.

## Work units

- [x] T1 — Implement deterministic issue/reference/provenance/review policy and tests,
  trusted identities, contributor scope rules and PR template.
  Route: delegated direct; new logic across multiple non-trivial files.
  Acceptance: complete multi-issue parsing, true-issue/same-repo checks, authorized label
  history, reopening timing, current-HEAD review and narrow owner exemption pass tests.
  Checks: focused Python unittest suite; observed RED -> GREEN before completion.
  Evidence: observed RED (missing policy module), then 40 passing Python tests;
  parent rerun passed all 40. Native assessment: medium; RDD off, writer verification.
  Commit: `10ca281a` — `feat(ci): define approved-issue contribution policy`.
- [x] T2 — Connect trusted API reconciliation, dedicated-App check publication, safe
  workflows, existing CI tests, CODEOWNERS and activation/runbook documentation.
  Route: delegated direct; network orchestration, workflows and security tests.
  Acceptance: invalidate -> deduplicated comment -> closure; revocation/edit recovery;
  HEAD/test-merge and shared-SHA protection; no untrusted code execution or deployment
  changes; pending external activation is explicit.
  Checks: focused policy/API/workflow/deployment tests; final PHP container/YAML lint,
  PHPStan, architecture, formatting normalization and one full 100% coverage run.
  Evidence: 87 contribution tests and 54 deployment tests passed with warnings as
  errors; native assessment high, independent verification passed locally. App and
  actual GitHub merge enforcement remain pending external activation.
  Commit: pending creation.

## Delivery and review

- Branch point: `6dca587b`.
- Branch: `feat/contribution-double-approval`.
- Initial forecast: approximately 1,200–1,800 authored changed lines; T2 exceeded this
  forecast because complete pagination, mutable-scope, race and fake-API tests belong
  with the adapter. Actual T2 implementation is 1,626 authored changed lines.
  The 400-line task heuristic is advisory; keep coherent behavior and tests together.
- Delivery strategy: `ask-on-risk`; user selected `feature-branch-chain`.
- Running authored count: T1 commit 771 lines (includes the recovery tracker).
- No PRs are created now. Planned review slices follow T1 and T2 work-unit boundaries;
  split further during future PR preparation if necessary to meet review budgets.
- RDD: off, decided by the global preference (observed before implementation).
- Native assessments: T1 medium; T2 high. RDD stays off, no native review started.
- Independent verification: required for high/unassessable security changes.
- Mirror: `odd/contribution-double-approval/tasks` in project `ares-irc-services`.

## Verification evidence

- Baseline working tree clean; no contribution guide, templates or CODEOWNERS.
- Existing main CI requires PHP and four migration-engine checks; review count is zero.
- Remote access remains read-only; repository ID 1167938167, owner ID 561369.
- T1: 40 Python policy tests passed, including parent spot check; no skips/failures.
- Initial untracked assessment was unassessable/high; explicit staging resolved it to
  medium. No review lifecycle was started while RDD is disabled.
- T2 writer: 87 contribution and 54 deployment tests; container lint; 442 YAML files;
  PHPStan exit 0; architecture 0 violations/uncovered/warnings/errors; normalizer and
  final formatter dry-run 0/1974 changes. Existing deployment bytes unchanged.
- Independent verifier: 87 contribution and 54 deployment tests with warnings as
  errors; stale-head, shared-SHA, feature-base and edited-scope adversarial probes.
- Full coverage executed exactly once: 6,190 tests, 85,287 assertions; exactly
  21,720/21,720 statements covered. Zero failures/skips/warnings/notices/incomplete/
  risky/deprecations. No PHP source changed; php -l is not applicable.
- Parent final spot check: all 87 contribution tests and container lint passed.
- Verified staged executable candidate: a3d6a30f870516680ac6e275cfa0f16ad3b35fe5;
  later changes only record verification in this tracker.
- Known conservative grammar limitation: comma followed by a bullet on the next
  line is rejected. Use one closing keyword per issue or the documented plain lists.
- Live App installation/API writes, workflow execution and merge-rule acceptance
  are NOT tested or active. Read docs/contribution-policy-setup.md before activation.

## Rollback boundary

Remove the new contribution-policy files and only the policy-test step in existing CI;
do not change production code, existing checks or deployment. External rules are not
changed by this implementation and require a separate authorized activation/reversal.

## Next step

Commit verified T2 and record its identity. Then the maintainer must authorize and
perform App/secret/label/protection setup and default-branch activation; no push,
pull request, merge or remote mutation has been performed by this implementation.
