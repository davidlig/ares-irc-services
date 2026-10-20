# Contributing to Ares IRC Services

Start with an issue, not a pull request. Develop only changes whose issue scope a
maintainer has approved. This keeps contributions aligned with the project and
avoids asking contributors to build work that cannot be accepted.

> **Automation is not active yet.** The policy core alone does not enforce these
> rules. The dedicated GitHub App, workflows, required checks and branch protection
> must be activated before automatic closure or merge blocking can be relied on.
> See the [maintainer activation guide](docs/contribution-policy-setup.md) for the
> pending external setup and acceptance checks.

## Contribution path

1. **Open an issue** describing the problem, proposed behavior and acceptance criteria.
2. **Wait for a maintainer's approval.** The exact `approved` issue label must be
   applied by an authorized human maintainer with current write/admin-equivalent
   access. The older `status:approved` label, a comment, or a label on a PR is not
   authorization. Maintainer identities are pinned by numeric GitHub user ID in
   [the policy configuration](.github/contribution-policy.json), not by username.
3. **Develop only the approved scope.** Every feature needs its own approved issue
   and acceptance criteria. Unrelated improvements or extra features need their
   own prior issue approval. Tests, documentation and incidental implementation
   necessary for the approved behavior can belong to that same issue.
4. **Open a PR with real closing references in its description.** Map each feature
   to its approved issue and criteria using the PR template. Multiple issues are
   allowed, but **every** closing or manually linked issue must be an actual issue
   in this repository with valid approval provenance. A manually linked issue
   alone does not replace a closing-keyword reference in the body.
5. **Complete review and CI.** Non-owner-authored PRs need an independent approval
   from an authorized human maintainer on the current HEAD commit, alongside all
   existing CI and main-branch protection requirements. New commits invalidate
   the current-HEAD approval, including empty commits. Address requests for changes
   before requesting approval again.

## Issue references and timing

Use `Fixes`, `Closes` or `Resolves` followed by issue references. Their supported
verb forms are case-insensitive and may include a colon. Local `#N`, qualified
`davidlig/ares-irc-services#N` and full GitHub issue URLs are accepted. For example:

```text
Fixes #123, #456
Resolves: https://github.com/davidlig/ares-irc-services/issues/789
```

Replace example numbers with your actual approved issues in **plain PR description
text**, outside comments or code fences. Separate explanatory prose into its own
sentence. Foreign repositories, PR links, malformed references, or any unapproved
linked issue invalidate admission; the policy never silently accepts just the
first reference. Ordinary mentions do not grant authorization.

Approval must precede the PR's opening or **latest reopening**. Applying or
reapplying `approved` after opening is not prior approval. Once automation is
activated, an invalid or unrequested PR will be automatically closed with a
friendly explanation. Correct the description or issue approval, then reopen the
PR yourself after all approvals are in place. Automation never reopens PRs.
An otherwise admitted PR missing review stays open, but cannot pass the policy
check. Removing approval or the approving maintainer's write access also invalidates
admission. Issue closure alone does not revoke approval.

Editing an approved issue's body or title changes the scope being authorized.
The approval must be reapplied by a maintainer after the latest edit; an old label
cannot authorize rewritten requirements. If a PR is already open, obtain that
fresh issue approval, then reopen the PR after approval as described above.

## Scope and exceptions

Maintainers must verify that each change actually matches its issue's approved
scope and acceptance criteria. Reference validation **does not automatically detect
unrelated features or prove semantic scope**. List each feature separately so this
review is straightforward.

Only PRs authored by the repository owner, stable user ID `561369`, are exempt from
independent PR review. That exemption does **not** waive prior issue approval,
current CI, the `Contribution policy` check, or main-branch protection.

For implementation and verification conventions, read [AGENTS.md](AGENTS.md).
Maintainers: follow the [activation guide](docs/contribution-policy-setup.md) for
App installation, trusted check source selection and branch protection. All external
configuration remains pending separate authorization.
