# Contributing to Ares IRC Services

Start with an issue, not a pull request. Develop only the scope explicitly
authorized for your contributor identity and source branch by an authorized maintainer.

> **Deployment state, 2026-10-20:** the dedicated App and native main protections
> are active. The branch-bound grant protocol below is **v2** and is not active on
> trusted main until this change is merged and deployed. Positive v2 live admission
> has not been tested. See the [maintainer guide](docs/contribution-policy-setup.md)
> for verified setup, migration and remaining acceptance checks.

## Contribution path

1. **Open an issue** with the problem, proposed behavior and acceptance criteria.
   Each feature needs its own issue. Unrelated extras require separate prior
   authorization; necessary tests, documentation and incidental implementation may
   belong to the feature they support. The issue must remain **open**.
2. **Agree the contributor and branch before development.** Supply your numeric
   GitHub user ID, source repository ID (your fork for a fork PR), exact branch name
   and target `main`. A username or branch name alone is not an identity.
3. **Wait for a maintainer's grant and both labels.** An allowlisted human with
   current write/admin-equivalent permission posts a standalone, unedited issue
   comment in this exact format, replacing the example values:

   ```text
   /authorize-contribution {"version":1,"author_id":123,"head_repository_id":456,"head_ref":"feat/example","base_ref":"main"}
   ```

   The maintainer then applies **`approved-grant:<comment_id>`**, followed by
   **`approved`**, in separate, strictly later steps. The issue must carry exactly
   one grant-pointer label. Extra/duplicate JSON keys, invalid IDs, edited comments,
   renamed pointers without provenance, and legacy `status:approved` do not qualify.
   Both label applications and the comment author require trusted current human
   authority. A PR label, self-approval or ordinary comment is not a grant.
4. **Develop only the authorized scope**, then open the PR on that exact source
   repository/branch. Every feature must map to its own issue, grant and acceptance
   criteria in the template. Multiple issues are allowed, but **all** closing and
   manually linked issues must qualify. The body also needs a real closing-keyword
   declaration, not only a manual link or an example in comments/code fences.
5. **Complete independent review and CI.** External-author PRs need an allowlisted
   human maintainer's independent approval on the current HEAD. New commits,
   including empty commits, invalidate that approval. CI, the dedicated App check
   and main protection remain mandatory; address active requests for changes.

## One PR per grant, not parallel work

A grant belongs to the author's **first PR creation or reopening in this repository
strictly after its original approval epoch**, including closed or merged PRs.
That opening consumes it even if the PR uses another branch, has no issue reference,
changes its body later or is closed. Only that same stable PR identity may reopen
using the consumed grant; a new PR needs a **new comment and approval**.

Do not authorize separate parallel PRs for one author. Grants for multiple issues
supporting the same PR must all qualify; otherwise an earlier unrelated opening can
consume them. Other authors' PRs and ordinary comments do not consume your grant.
The selected PR must still match the grant's author, source repository, exact branch
and `main` target. Choosing a different branch does not leave the grant available.

The **original approval epoch** is the first `approved` application after the first
trusted application of that grant's pointer. Removing/reapplying either label does
not reset consumption or create a fresh epoch for the old comment. Missing or
invalid current pointers never fall back to an older comment.

## References, timing and recovery

Supported closing keywords include `Fixes`, `Closes` and `Resolves` and their verb
forms, case-insensitively, with an optional colon. Examples:

```text
Fixes #123, #456
Resolves: https://github.com/davidlig/ares-irc-services/issues/789
```

Replace the numbers with actual issues in plain PR text. Qualified
`davidlig/ares-irc-services#N` is also accepted. Foreign repositories, PR links,
malformed extra references or any invalid linked issue block admission; ordinary
mentions do not grant authorization.

For an unambiguous declaration, put closing references on their own line, such as
`Closes #123`, and keep explanatory prose in a separate paragraph. Commas,
semicolons and conjunctions in ordinary prose (for example, “reproduced, fixed in
one batch”) do not create closing declarations. They are separators only within
an actual reference list. A malformed continuation such as `Fixes #123, closes
nonsense` still blocks admission; a valid later declaration cannot hide it.

The check evaluates trusted `main`, not policy code proposed by the PR. If an
older trusted parser mistakes ordinary prose for a closing declaration, reword
only that prose while retaining the real closing references. A candidate parser
correction cannot change its own admission check before it reaches trusted main.

All steps use GitHub timestamps and must be strictly ordered:
**latest issue scope edit/reopening < grant comment < grant-pointer application <
original approved application < PR opening/latest reopening**. Equal timestamps
fail conservatively. Do not batch both labels; verify distinct times before opening.

If only the PR description has an invalid or ambiguous closing reference, correct
that description and manually reopen the **same** PR after verifying its linked
issues and grants. A description-only correction does not edit issue scope or
renew a grant: the original first-consumer binding still applies. Do not create a
replacement PR or cycle approval labels to recover it. If the correction adds a
new behavior, that behavior needs its own issue and grant before reopening.

Closing an issue revokes admission. Reopening it, changing its body/title, editing
or deleting the grant, losing maintainer authority, or consuming it with another PR
requires recovery:

1. Keep/reopen the issue only if its scope is still wanted; finalize its content.
2. Remove `approved` and old `approved-grant:*` labels. A maintainer posts a **new,
   unedited command comment**, applies its new pointer, then applies `approved`.
3. After all linked issues qualify, manually reopen the intended PR. If it is still
   open, close it before reopening after authorization. The next author opening must
   be this PR, not unrelated work. A new PR also requires a fresh grant and approval.

Automation never reopens PRs or retroactively approves work, including owner work.
Under v2, old `approved` labels alone are invalid; there is no grandfathering.
GitHub permits PR creation before validation. Reconciliation is asynchronous and
closes invalid PRs only after blocking checks and posting a friendly comment. An
admitted PR missing review stays open but cannot pass. API failures are unknown,
not proof of a contribution violation; never bypass checks to work around an outage.

## Scope and owner exception

Maintainers must verify each feature against its issue's authorized scope and
criteria. Binding a grant **does not automatically detect unrelated features or
prove semantic scope**. No AI semantic approval is part of this policy.

Only PR author ID `561369` is exempt from independent PR review. The exemption does
not waive issue/grant requirements, current CI, the `Contribution policy` check or
main protection. It is not an exemption for anyone else's PR merged by the owner.

Read [AGENTS.md](AGENTS.md) for implementation/testing conventions and the
[maintainer guide](docs/contribution-policy-setup.md#grant-issuance-and-v2-migration)
for numeric-ID lookup, grant issuance and migration.
