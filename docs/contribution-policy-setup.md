# Activate approved-issue contribution protection

**Status: PENDING external activation.** This branch contains implementation, not
an installed App, active workflows or enforced repository settings. Nothing here
changes GitHub remotely. Obtain explicit authorization for each remote setup change;
local development permission does not authorize App installation, secret upload,
branch protection, push or merge.

## Protection model

| Layer | Required outcome | Bypass |
|-------|------------------|--------|
| Existing classic `main` protection | PR required, strict current CI and dedicated-App policy check | None, including admins |
| Additional **review-only** `main` ruleset | One approval, CODEOWNERS, stale dismissal | Only user `davidlig` (ID `561369`), **For pull requests only** |
| Dedicated App `Contribution policy` check | Every linked issue approved before latest PR opening; independent current-HEAD review for external authors | Author ID `561369` skips only the independent review portion |
| Human scope review | Each feature matches its own approved issue and acceptance criteria | No automated semantic detector |

Native bypass applies to the **person merging**, not the PR author. The custom App
check closes that gap: even when the owner uses the review-only ruleset bypass on
an external author's PR, the owner must first approve its current HEAD. For the
owner's own PR, use that narrow review bypass; issue approval, CI, the App check and
classic protection remain mandatory. Do not add a broad admin or `always` bypass.

[Rulesets are layered with existing protections](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets).
The [user bypass feature](https://github.blog/changelog/2026-05-07-repository-rulesets-user-bypass-and-branch-renaming/)
provides the individual owner entry; no organization-wide exemption is needed.

## 1. Register and install the dedicated App

Use a distinct App for this repository's policy checks, not the GitHub Actions App.

1. In the owner account: **Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Choose a unique name, for example `Ares contribution policy`, and the repository
   homepage URL. Do not configure user OAuth or request user authorization.
3. In **Webhook**, clear **Active**. There is no webhook server or external service.
4. Under **Repository permissions**, set **Checks: Read and write** only.
   **Metadata: Read-only** is implicit. Leave every other repository, account and
   organization permission unset; do not grant contents, issues or PR access to this App.
5. Select installation on **Only on this account**, then create the App. Record its
   numeric **App ID**, not its Client ID or Installation ID.
6. Generate a private key. Store it securely; never commit or print it.
7. Open **Install App → Install**, select **Only select repositories**, and choose
   `davidlig/ares-irc-services` only. Confirm repository ID `1167938167` and owner
   user ID `561369` before proceeding.

GitHub's [App registration guide](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/registering-a-github-app)
and [workflow authentication guide](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/making-authenticated-api-requests-with-a-github-app-in-a-github-actions-workflow)
describe the setup screens. Check-run writes require the App's Checks permission;
no personal token or `GITHUB_TOKEN` fallback is implemented.

## 2. Configure Actions and the approval label

In **Repository → Settings → Secrets and variables → Actions**:

- **Variables → New repository variable**: `CONTRIBUTION_APP_ID`, the numeric App ID.
- **Secrets → New repository secret**: `CONTRIBUTION_APP_PRIVATE_KEY`, the full PEM key.

In **Settings → Actions → General**, allow the two pinned official actions used by
these workflows and allow `pull_request_target` under any applicable repository or
organization Actions event policy. The workflow requests only `contents: read`,
`issues: write`, and `pull-requests: write` for `GITHUB_TOKEN`. Verify those effective
permissions, including any organization restrictions. Do not grant extra permissions
or enable automatic PR review approvals to work around failures.

Create the exact issue label **`approved`** through **Issues → Labels → New label**.
Do not rename `status:approved` into it or bulk-transfer historical trust. An existing
`approved` label is not sufficient evidence: audit its issue label events and apply
fresh authorized approvals as needed. Only a configured stable human maintainer ID
with current write/admin-equivalent access can authorize an issue. `maintain` counts
as write; `triage` does not. A PR's label or approval comment grants no issue authority.

The initial allowlist contains only user ID `561369`. Adding another maintainer
requires an intentional reviewed change to `.github/contribution-policy.json` on
trusted `main`; display names never grant access. `.github/CODEOWNERS` assigns all
files, including itself, the workflows and trust configuration, to `@davidlig`.

## 3. Bootstrap on trusted main, then require the App source

1. After authorization, land the policy files and workflows on default branch
   `main` through the existing repository process. The privileged workflow always
   checks out that trusted branch, never a PR head, fork, merge ref or artifact.
2. Use **Actions → Contribution reconciliation → Run workflow → main**. Verify that
   the App token is created and the live scan completes. With no open PR there is
   no commit needing a policy check.
3. For the first check, approve a legitimate issue **before** opening a small scoped
   PR. Wait for `Contribution policy` on both its HEAD and GitHub test-merge SHA.
   Complete the required owner review when the author is not the owner. Verify the
   check-run's App is the dedicated App, not `github-actions` or a same-name Actions job.
4. Only after this first genuine App check exists, make that check required below.
   Do not select an absent context by guesswork or bind it to “Any source.”

The action pins were verified against public official tag endpoints on 2026-10-20:
[checkout v6](https://github.com/actions/checkout/commit/d23441a48e516b6c34aea4fa41551a30e30af803)
and [create-github-app-token v2](https://github.com/actions/create-github-app-token/commit/fee1f7d63c2ff003460e3d139729b119787bc349).
Both use complete commit hashes. Token scope is explicitly restricted to this
repository and Checks write; the action revokes the installation token at job end.

## 4. Preserve classic main protection and existing CI

Open **Settings → Branches → Branch protection rules → main → Edit**. Preserve the
existing rule and all unrelated settings; do not replace it with a relaxed ruleset.

- **Require a pull request before merging**: enabled. Keep the classic rule's
  approving-review count at its baseline **0**; review requirements live only in
  the additional ruleset, so the owner's narrow review bypass cannot bypass CI.
- **Require status checks to pass before merging**: enabled.
- **Require branches to be up to date before merging**: enabled (`strict: true`).
- Preserve these five existing check contexts and their **GitHub Actions** source:
  - `PHP 8.5 — Lint, CS & Tests`
  - `Migrations — sqlite`
  - `Migrations — mariadb`
  - `Migrations — postgres`
  - `Migrations — mysql`
- Add **`Contribution policy`**, with the dedicated App as **expected source**.
  The source's numeric `app_id` is the App ID from registration, not the App's
  installation ID. An equally named Actions job or another App must not satisfy it.
- **Do not allow bypassing the above settings**: enabled (`enforce_admins: true`).
  No bypass actors or bypass allowances, including the owner, on this baseline rule.
- **Allow force pushes** and **Allow deletions**: disabled.

Depending on the current GitHub UI, zero reviews may be represented by leaving
**Require approvals** unchecked while keeping the PR requirement enabled. Verify the
resulting effective rule, not just the displayed checkbox. See [protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
and [expected check sources](https://docs.github.com/en/rest/branches/branch-protection).
Do not weaken existing CI, deployment dependencies or production environment controls.

## 5. Add only the review ruleset

Open **Settings → Rules → Rulesets → New ruleset → New branch ruleset**:

1. Name it `Independent contribution review`, set enforcement to **Active**, and
   target only `refs/heads/main` (or select **Include default branch** if main remains
   the fixed default). Add no exclusions.
2. Under **Bypass list → Add bypass**, choose **User**, select `davidlig`, verify ID
   `561369`, and choose **For pull requests only** (`bypass_mode: pull_request`).
   This must be the only bypass actor. Never select repository administrator roles,
   teams, Apps, `Always allow`, or a classic-protection bypass.
3. Enable only **Require a pull request before merging** and its review options:
   **1 required approval**, **Require review from Code Owners**, and
   **Dismiss stale pull request approvals when new commits are pushed**.
4. Leave **Require approval of the most recent reviewable push** disabled. A sole
   owner who pushes a contributor fix would otherwise need a second reviewer.
   Review independence is from the **PR author**, not the latest pusher.
5. Do not add status checks, force/deletion rules or unrelated policies here. This
   ruleset must remain review-only; its bypass must not bypass baseline CI/protection.
   No new conversation-resolution requirement is part of this change.

See [creating rulesets](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/creating-rulesets-for-a-repository)
and [review rule options](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets).
Public repositories support these protections on GitHub Free; private repositories
need an eligible paid plan (Pro, Team or Enterprise as appropriate). Confirm the
account's available controls before activation; do not claim enforcement from YAML.

## 6. Acceptance checks after authorized activation

These require real App installation and GitHub protection, and are **PENDING**.
Local fake-API tests are not proof that GitHub actually blocks merge or direct push.
Use disposable branches/issues/PRs with explicit authorization and no production work.

| Scenario | Expected result |
|----------|-----------------|
| PR without a real closing declaration, including only a manual link | Checks blocked, one friendly bot comment, PR closed |
| Multiple closing/manual links, one unapproved/foreign/deleted/PR object | All links checked; invalidation before comment before closure |
| Unapproved extra feature concealed behind an approved issue | Maintainer rejects semantic scope; automation does not detect intent |
| Ordinary contributor or bot applies `approved` | Label removed where permitted, no trust granted, affected PRs reconciled immediately |
| Older unauthorized event followed by legitimate maintainer approval | Current legitimate approval preserved |
| Approval applied after opening, or label removed and reapplied | PR closed; only a later manual reopening after all approvals can qualify |
| All issues properly approved before latest reopening | Admission valid; bot never reopens automatically |
| Closed issue still carries valid approved provenance | Issue state alone does not prevent admission |
| Approved issue body/title edited, even by its author | Old scope approval removed; fresh maintainer approval and later PR reopening required |
| Old issue-edit wakeup arrives after legitimate reapproval | Newer approval preserved by fresh timestamp/history reads |
| Approval removed or approving maintainer loses write access | Policy invalidated, comment reused, PR closed |
| Valid issue but missing review | PR remains open; policy check blocks merge |
| Current-HEAD owner approval of external-author PR | Policy can succeed; existing CI must still pass |
| Empty/new commit, dismissed approval or active request for changes | Current approval invalidated; COMMENTED cannot erase review state |
| Owner-authored PR | Independent review exemption only; own approved issue, policy check and CI still required |
| Owner merges an external-author PR using review-only bypass | App still requires independent owner review on current HEAD |
| Other admin/user tries direct main push or broad bypass | Baseline protection denies it |
| Fork PR, including changes to policy/workflows | Privileged run uses trusted main only, no PR code or artifacts executed |
| Forged same-name Actions check | Cannot satisfy dedicated-App expected source |
| Shared HEAD/test-merge SHA across open PRs | Success only if every associated open PR qualifies; closed sibling recomputed |
| Body/head/base/merge/link/issue changes during a run | Snapshot reread blocks stale success or stale closure; bounded rescan |
| API 403/429/5xx, timeout, malformed response or incomplete pagination | Unknown, no false admission violation; checks blocked when writes possible |
| Comment write denied or uncertain | No closure; blocked check and replay using bot identity plus marker |
| Schedule/manual rerun after metadata-only change | All open PRs re-evaluated, mutable check runs updated rather than appended |

## Operations and limitations

- `pull_request_target`, issue/label events, pushes to main and review signals wake
  trusted reconciliation. The permission-free review signal checks out nothing and
  publishes no artifacts. Its conclusion is never approval; `workflow_run` is only
  a wakeup and validates the canonical run repository before a fresh API scan.
- Each run scans **all open PRs**, serializes on `contribution-policy` with cancellation
  disabled, and unions paginated `closingIssuesReferences(excludeUserLinked:false)`
  with body declarations. Manual links, permission changes and some metadata changes
  may have no immediate usable event; the `7,22,37,52` schedule and manual dispatch
  provide eventual reconciliation. GITHUB_TOKEN label removals do not need a new
  event: the current run directly re-evaluates affected PRs.
- GitHub schedules can be delayed, disabled after repository inactivity, or unavailable
  during outages. Pending runs can be replaced even with cancellation disabled;
  fresh complete scans prevent lost event snapshots from becoming authority.
- Live snapshots include body, HEAD, base, test-merge SHA, links, issue label history,
  current actor permissions and reviews. Issue body edits use authenticated GraphQL
  `Issue.lastEditedAt`; title changes also use paginated `renamed` events. Latest
  approval must be strictly later than the latest scope edit (equal timestamps
  fail conservatively). Issue `updated_at` is deliberately not used, because
  comments/labels also change it. This detects edits, not semantic scope alignment.
  The [public GitHub schema](https://docs.github.com/public/fpt/schema.docs.graphql)
  defines the body-edit timestamp. A stable run takes three complete fresh
  snapshots: the initial baseline, validation after the approval-label audit, and
  a final guard after one finite batch of App check writes. Every snapshot clears
  its issue/permission caches and re-reads actual metadata. Repository-wide scans
  are never nested inside the per-SHA publication loop. A changed final snapshot,
  including new shared-SHA siblings, invalidates every held SHA before a rescan;
  if invalidation fails, the run stops unavailable rather than claiming success.
  An intermediate App write error also attempts all held-SHA invalidations and
  returns unsuccessful. If writes remain unavailable, earlier greens may remain
  visible; the run never claims they were revoked or returns partial success.
  Closure still has fresh checks before the comment and before closing. There are
  at most eight rounds (at most 33 full snapshots), independent of PR/SHA count.
  Closing an invalid sibling consumes a round; larger backlogs remain blocked and
  require subsequent runs. Pagination is capped at 200 pages per connection; reads
  use a 20-second timeout and at most three bounded attempts. Mutations are never
  blindly retried after uncertain outcomes.
- Metadata request complexity is linear in open PRs and their unique dependencies
  and paginated records for a fixed round budget, not quadratic in SHA count. The
  regression fixture gives each external PR its own approved issue, current-HEAD
  owner review and two unique SHAs. With no extra pages, `n` stable PRs use exactly
  `18n + 8` metadata REST requests and `7n` GraphQL requests, plus `6n + 1` requests
  through the separate App client. The 20-PR fixture completes with 368 metadata
  REST requests under a simulated 1,000-REST-request budget. GraphQL request counts
  are measured separately, **not** treated as REST quota or measured primary-point
  costs. Four scheduled scans per hour would already use 1,472 metadata REST
  requests for that fixture, before events, retries or churn. This per-run proof
  is not a guarantee that aggregate hourly traffic fits every installation's quota.
- Mutable App check runs cover current HEAD **and** known test-merge SHAs. Every open
  PR sharing a SHA, across either role, must qualify. Unknown mergeability/missing
  merge SHA blocks the known head rather than claiming complete coverage. Fork
  check-runs may have an empty GitHub `pull_requests` list; aggregation uses live PR
  metadata rather than that field. See the [check-run API](https://docs.github.com/en/rest/checks/runs).
- Reconciliation is asynchronous, not a transaction with a human merge. A change
  after the last read can race publication/merge. A permission/API outage can prevent
  invalidating an old success; an unavailable job is not proof that GitHub's existing
  check state changed. No immediate, outage-proof revocation guarantee is made.
  Keep strict native CI/protection and manually stop merging when reconciliation is
  unavailable; do not weaken the required check to clear an outage.
- The privileged workflow never runs Composer, contributor tests, caches, PR code,
  fork checkout or downloaded artifacts. Ordinary unprivileged CI runs the Python
  regression tests. Existing deployment job bytes and required CI remain unchanged.
- Review effective permissions and App key rotation periodically. Revoke a compromised
  key, replace the repository secret through an authorized operation, and reconcile
  before resuming merges. Do not log tokens, keys, untrusted bodies or raw API errors.
- To pause enforcement, first make a separately authorized protection decision. Merely
  disabling Actions can leave stale success or permanently pending checks; it is not
  a reliable policy kill switch. Do not silently remove requirements or bypasses.

Contributor-facing scope and timing rules are in [CONTRIBUTING.md](../CONTRIBUTING.md).
