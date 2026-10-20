# Activate approved-issue contribution protection

## Deployment state

**Verified external setup, 2026-10-20:** dedicated App **5389596** is configured,
its check source is required by classic main protection, and the review-only ruleset
**25447311** is Active. Independent REST/GraphQL readback confirmed strict checks,
admin enforcement, zero classic bypass allowances and the narrow user-only review
bypass. [Reconciliation run 39355717869](https://github.com/davidlig/ares-irc-services/actions/runs/39355717869)
succeeded with `reconciled; closed=0; blocked=0` on an empty backlog;
[main CI 39353252092](https://github.com/davidlig/ares-irc-services/actions/runs/39353252092)
succeeded. Genuine policy checks from App 5389596 were observed on historical PR heads.
These facts verify setup, not every live acceptance scenario.

**Branch-bound authorization v2: PENDING deployment and live acceptance.** Current
trusted main still runs the earlier label-only policy until this change is merged
and deployed. Positive v2 live admission has not been tested. Local implementation
has not closed, reopened, approved or otherwise mutated PR51. Its earlier
`metadata_or_write_unavailable` failures remain unexplained at the endpoint/HTTP
level; neither setup success nor local mocks prove that operational cause fixed.

Sections 1–5 remain reusable installation/audit instructions, not a request to repeat
completed mutations. Obtain explicit authorization for each remote setup change;
local development permission does not authorize installation, secret upload,
protection changes, workflow dispatch, experiments, push or merge.

## Protection model

The native layers below are configured; the App row describes the v2 target after
deployment, not a claim that current main already enforces branch-bound grants.

| Layer | Required outcome | Bypass |
|-------|------------------|--------|
| Existing classic `main` protection | PR required, strict current CI and dedicated-App policy check | None, including admins |
| Additional **review-only** `main` ruleset | One approval, CODEOWNERS, stale dismissal | Only user `davidlig` (ID `561369`), **For pull requests only** |
| Dedicated App `Contribution policy` check | Every linked issue open, with prior author/fork/branch-bound grant and original approval epoch; independent current-HEAD review for external authors | Author ID `561369` skips only the independent review portion |
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
as write; `triage` does not. A PR's labels, ordinary comments or self-approval grant
no issue authority. The v2 command below must be on the issue, with its exact pointer
and independently trusted label applications.

The initial allowlist contains only user ID `561369`. Adding another maintainer
requires an intentional reviewed change to `.github/contribution-policy.json` on
trusted `main`; display names never grant access. `.github/CODEOWNERS` assigns all
files, including itself, the workflows and trust configuration, to `@davidlig`.

## Grant issuance and v2 migration

### Identify the contributor and source before development

These illustrative `gh api` commands are **read-only**. Replace placeholders; do
not run against another destination/session without authorization:

```sh
# Stable contributor ID, not their mutable login.
gh api users/CONTRIBUTOR --jq '{login, id}'
# Use the actual fork/source repository, not the canonical base repository ID.
gh api repos/FORK_OWNER/FORK_REPO --jq '{full_name, id}'
# When diagnosing an existing PR, these are its actual REST PR/source identities.
gh api repos/davidlig/ares-irc-services/pulls/PR_NUMBER \
  --jq '{pr_id: .id, number, author_id: .user.id, head_repository_id: .head.repo.id, head_ref: .head.ref, base_ref: .base.ref}'
```

| Identifier | Meaning / source |
|------------|------------------|
| `author_id` | GitHub user numeric `id`; names are display only |
| `head_repository_id` | Numeric source repository `id`; forks differ from base ID 1167938167 |
| Grant `comment_id` | Numeric issue-comment `id`, also the `N` in `#issuecomment-N` copied from its link |
| PR identity | REST pull object's `id`, not its number, issue ID, timeline `source.issue.id` or GraphQL node ID |
| App ID | Registration App ID, currently 5389596; never Client ID or Installation ID |

Before a PR exists, confirm its planned source branch with the contributor. The
command uses the exact branch name without `refs/heads/`; base must be `main`.
Source repository renames do not change its numeric identity. Do not grant a
contributor extra repository access merely to make them assignable to an issue;
assignment is not part of this protocol.

### Issue one prior grant, then approve its epoch

1. Keep the issue **open** and finalize its title, body and acceptance criteria.
   Every feature needs its own issue; incidental necessary tests/docs may share it.
   For replacement authorization, first remove `approved` and old
   `approved-grant:*` labels. Never rename an old pointer to a new comment ID.
2. An allowlisted human with current write/admin-equivalent access posts this as
   one standalone issue comment, with actual values and **no Markdown wrapper**:

   ```text
   /authorize-contribution {"version":1,"author_id":123,"head_repository_id":456,"head_ref":"feat/example","base_ref":"main"}
   ```

   Exact keys only; version must be integer 1, IDs positive integers (not booleans),
   and refs valid. No extra/duplicate keys. Never edit the command: even an edit
   that restores its old text invalidates it when GitHub reports differing
   creation/update timestamps. Post a new comment instead.
3. Copy that comment's link, extract `N` from `#issuecomment-N`, and create the
   exact repository label `approved-grant:N` if absent (**Issues → Labels → New
   label**). Apply it to this issue. There must be exactly one active pointer.
   Creating a label alone is not authorization; the issue application needs trusted
   human provenance. The referenced comment must belong to this same issue.
4. In a **later separate operation**, apply exact `approved`. Grant comment,
   pointer application and approval must have strictly increasing GitHub timestamps,
   all after the latest issue body/title edit or reopening and before PR opening.
   Do not batch both labels. Wait between steps and verify server timestamps;
   equality fails conservatively and can require a new grant rather than relabeling.
5. Only then may the contributor develop the authorized scope and open the planned
   PR with actual closing references. Multiple issue grants may support that same
   PR, but every closing/manual linked issue must independently qualify.

Verify comment identity without printing its body:

```sh
gh api repos/davidlig/ares-irc-services/issues/comments/COMMENT_ID \
  --jq '{id, issue_url, author_id: .user.id, created_at, updated_at}'
gh api repos/davidlig/ares-irc-services/issues/ISSUE_NUMBER/events --paginate \
  --jq '.[] | select(.event == "labeled" or .event == "unlabeled") | {id, event, label: .label.name, actor_id: .actor.id, created_at}'
```

Both labels' current effective applications and the comment author must be trusted
human IDs with current write/admin-equivalent permission. Names, PR labels, a copied
comment from another issue, bots and `status:approved` cannot confer authority.

### Consumption, revocation and recovery

The **original epoch** is the first `approved` application following the first
trusted application of the selected grant pointer. It must still be the current
approval application. Reapplying either label cannot renew the old comment or reset
consumption. Deleting/editing the pointed comment, missing provenance or malformed/
multiple pointers fails closed; there is no search for a usable older grant.

A complete paginated history selects the author's **first PR creation or reopening
in this canonical base repository strictly after the epoch**, ordered by server time
and stable PR/event IDs. It includes closed/merged PRs, regardless of current body,
issue links, source branch or base branch. A PR created before the epoch may consume
it by reopening later. Only the selected stable PR ID can use the grant; its current
author, source repository/ref and main target must then match. Body edits, closing
that PR, deleting its branch or removing an issue reference never free consumption.

Do not issue grants for separate parallel PRs by the same author. An unrelated or
wrong-branch opening consumes first and invalidates the intended PR. Multiple grants
for different features/issues can support **one** PR, not parallel author PRs.
Reopening the same consuming PR is allowed while its original issue/grant/approval
remain valid; a new PR requires a new grant comment, pointer and approval.

Issue closure revokes admission. Reopening it requires fresh authorization after
that reopening. Scope edits also require a new unedited command after the edit,
not merely reapplying `approved`. To recover, remove approval and old pointer labels,
finalize/open the issue, issue a new comment and pointer, then approve. Ensure the
intended PR is the next author opening; close/reopen it manually only after all its
issues qualify. Never auto-reopen, auto-review-approve or bypass CI.

**Migration:** historical `approved` alone is invalid under v2. No grandfathering,
retrospective automatic owner approval or automatic reopening is performed. Before
deploying v2, audit open work and obtain new per-issue grants through this sequence.
The owner has the same issue/grant requirements; only independent PR review is exempt.
Maintainers still review semantic scope; no AI or automatic semantic proof is added.

## 3. Bootstrap on trusted main, then require the App source

1. After authorization, land the policy files and workflows on default branch
   `main` through the existing repository process. The privileged workflow always
   checks out that trusted branch, never a PR head, fork, merge ref or artifact.
2. Use **Actions → Contribution reconciliation → Run workflow → main**. Verify that
   the App token is created and the live scan completes. With no open PR there is
   no commit needing a policy check.
3. For an authorized v2 acceptance PR, issue a legitimate branch-bound grant and
   both labels **before** opening the small scoped PR. Wait for `Contribution policy` on both its HEAD and GitHub test-merge SHA.
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
   No new conversation-resolution requirement is part of this change. If GitHub
   inserts **additional approval for unattributed Copilot pull requests**, clear
   it to retain the exact one-approval design; its API parameter
   `require_extra_approval_for_unattributed_changes` was read back `false` here.

See [creating rulesets](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/creating-rulesets-for-a-repository)
and [review rule options](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets).
Public repositories support these protections on GitHub Free; private repositories
need an eligible paid plan (Pro, Team or Enterprise as appropriate). Confirm the
account's available controls before activation; do not claim enforcement from YAML.

## 6. Acceptance checks after authorized activation

The external App/protection configuration above has verified readback. The full
scenario matrix below, especially positive v2 admission and actual merge/direct-push
denial, remains **PENDING live acceptance**. Local fake-API tests are not proof of
GitHub's merge enforcement. Obtain separate specific authorization before disposable
branches/issues/PRs, workflow dispatches or direct-push experiments; no production work.

| Scenario | Expected result |
|----------|-----------------|
| PR without a real closing declaration, including only a manual link | Checks blocked, one friendly bot comment, PR closed |
| Multiple closing/manual links, one unapproved/foreign/deleted/PR object | All links checked; invalidation before comment before closure |
| Unapproved extra feature concealed behind an approved issue | Maintainer rejects semantic scope; automation does not detect intent |
| Ordinary contributor or bot applies `approved` or a grant pointer | Untrusted applications removed where permitted; no authority granted |
| Missing/edited/deleted/foreign grant, malformed command or multiple pointers | Admission invalid; no old-comment fallback |
| Valid-looking grant names another author/fork/ref | Admission invalid, including owner-authored work |
| New author PR reuses a consumed grant | Rejected; previous PR closure/body edit cannot free it |
| Earlier wrong-branch or unrelated author opening/reopening | Consumes the epoch; intended PR needs new grant/approval |
| Same consuming PR manually reopens | May qualify with unchanged valid original epoch and all other checks |
| Older unauthorized event followed by legitimate maintainer approval | Fresh legitimate label preserved; original-epoch/grant validity still required |
| First approval applied after opening | PR cannot qualify until a later consuming reopening after authorization |
| Approval or old pointer reapplied to renew a used grant | Rejected; new comment, pointer and approval required |
| Every issue has a valid bound grant/original epoch and this PR is its first consumer | Admission may qualify after the intended reopening; bot never reopens automatically |
| Closed issue retains old grant/approval | Admission revoked; reopening needs fresh grant/approval |
| Approved issue body/title edited, even by its author | Old authorization invalid; new command/pointer/approval after edit, then intended PR reopening |
| Old issue-edit wakeup arrives after replacement grant and approval | New legitimate authorization preserved by fresh timestamp/history reads |
| Approval removed or approving maintainer loses write access | Policy invalidated, comment reused, PR closed |
| Valid issue but missing review | PR remains open; policy check blocks merge |
| Valid grants and current-HEAD owner approval of external-author PR | Policy can succeed; existing CI must still pass |
| Empty/new commit, dismissed approval or active request for changes | Current approval invalidated; COMMENTED cannot erase review state |
| Owner-authored PR | Independent review exemption only; own approved issue, policy check and CI still required |
| Owner merges an external-author PR using review-only bypass | App still requires independent owner review on current HEAD |
| Other admin/user tries direct main push or broad bypass | Baseline protection denies it |
| Fork PR, including changes to policy/workflows | Privileged run uses trusted main only, no PR code or artifacts executed |
| Forged same-name Actions check | Cannot satisfy dedicated-App expected source |
| Shared HEAD/test-merge SHA across open PRs | Success only if every associated open PR qualifies; closed sibling recomputed |
| Body/head/base/merge/link/issue changes during a run | Snapshot reread blocks stale success or stale closure; bounded rescan |
| API 403/429/5xx, timeout, malformed response or incomplete history/comment pagination | Unknown, no false admission violation; known checks blocked when writes possible |
| Permission/events/comments endpoint returns 404 | Unknown, not evidence that the issue does not exist |
| Comment write denied or uncertain | No closure; blocked check and replay using bot identity plus marker |
| Schedule/manual rerun after metadata-only change | All open PRs re-evaluated, mutable check runs updated rather than appended |

## Operations and limitations

- `pull_request_target`, issue/label events (including issue close/reopen), grant
  comment creation/edit/deletion, pushes to main and review signals wake
  trusted reconciliation. The permission-free review signal checks out nothing and
  publishes no artifacts. Its conclusion is never approval; `workflow_run` is only
  a wakeup and validates the canonical run repository before a fresh API scan.
- Each run scans **all open PRs** and reads complete **all-state PR opening history**
  once per fresh snapshot, shared across issues/grants rather than per SHA. It
  serializes on `contribution-policy` with cancellation disabled, and unions
  paginated `closingIssuesReferences(excludeUserLinked:false)`
  with body declarations. Manual links, permission changes and some metadata changes
  may have no immediate usable event; the `7,22,37,52` schedule and manual dispatch
  provide eventual reconciliation. GITHUB_TOKEN label removals do not need a new
  event: the current run directly re-evaluates affected PRs.
- GitHub schedules can be delayed, disabled after repository inactivity, or unavailable
  during outages. Pending runs can be replaced even with cancellation disabled;
  fresh complete scans prevent lost event snapshots from becoming authority.
- Live snapshots include body, HEAD, source repository/ref, base, test-merge SHA,
  links, issue state/reopening and both label histories, selected grant comments,
  historical PR creation/reopening events, current actor permissions and reviews.
  Issue body edits use authenticated GraphQL `Issue.lastEditedAt`; title changes
  also use paginated `renamed` events. The grant comment must be strictly later than the latest scope edit or issue reopening;
  pointer and original approval must follow in order (equal timestamps fail
  conservatively). Issue `updated_at` is deliberately not used, because
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
- Metadata request complexity is linear in open PRs, retained historical PRs and
  unique dependencies/paginated records for a fixed round budget, not quadratic in
  SHA count. Closed/merged PR opening histories add traffic even with few open PRs.
  The regression fixture gives each external PR its own approved issue, current-HEAD
  owner review and two unique SHAs. With no extra pages, `n` stable PRs use exactly
  `22n + 11` metadata REST requests and `7n` GraphQL requests, plus `6n + 1` requests
  through the separate App client. Each issue has a valid grant; authors are distinct.
  The 20-PR fixture uses **451 REST, 140 GraphQL and 121 App requests**, passing
  a simulated 1,000-metadata-REST-request budget. GraphQL request counts
  are measured separately, **not** treated as REST quota or measured primary-point
  costs. Four scheduled scans per hour would already use 1,804 metadata REST
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
- New diagnostic output contains only fixed enum **stage, operation, principal,
  category and numeric HTTP status** (or `none`), with separate `primary` and
  `cleanup` failures. It never includes upstream bodies/messages, arbitrary URLs,
  PR bodies, tokens or PEM contents. HTTP errors are distinguished from response
  validation/mismatch and unexpected failures. Failed cleanup does not replace the
  primary error or permit partial success. Only an initial issue GET 404/410 is
  definitive missing-issue evidence; permission, event or comment 404 remains
  unavailable. Investigate safe operation/stage/status fields before changing code
  or permissions. The earlier live failure's endpoint/cause is still unproven;
  v2 diagnostics cannot retroactively recover information absent from old logs.
- Review effective permissions and App key rotation periodically. Revoke a compromised
  key, replace the repository secret through an authorized operation, and reconcile
  before resuming merges. Do not log tokens, keys, untrusted bodies or raw API errors.
- To pause enforcement, first make a separately authorized protection decision. Merely
  disabling Actions can leave stale success or permanently pending checks; it is not
  a reliable policy kill switch. Do not silently remove requirements or bypasses.

Contributor-facing scope and timing rules are in [CONTRIBUTING.md](../CONTRIBUTING.md).
