## Authorized scope

List every feature separately. Each needs its own open issue, prior branch-bound
maintainer grant and approved acceptance criteria. Necessary tests, docs and
incidental implementation may map to the feature they support.

| Feature | Open approved issue | Grant comment / pointer label | Approved criterion | Changes and verification |
|---------|---------------------|-------------------------------|--------------------|--------------------------|
| Describe one feature | Link its issue | Link the command and identify approved-grant:comment_id | Identify the agreed criterion | Explain how it is met |

## Grant binding and consumption

- Contributor numeric user ID:
- Source repository numeric ID (the fork for a fork PR):
- Exact source branch and target main:
- Original approval time / this PR opening or latest reopening:
- First author PR opening/reopening after that approval (this same PR must consume it):

Do not reuse another PR's grant or schedule parallel author PRs against these grants.
Removing/reapplying labels does not renew an old grant; a new PR requires a new
command comment and approval. Every linked issue must qualify, including manual links.

## Closing references

<!--
Replace this comment with actual closing references outside comments/code fences.
Example syntax only, with numbers replaced by your open, authorized issues:
Fixes #123, #456
Each issue needs its own unedited trusted human grant comment, one matching
approved-grant:<comment_id> label, then approved, all before this opening/reopening.
-->

## Verification

- Commands run and observed results:
- Checks not run, failures or environment limitations:

## Contributor checklist

- [ ] Every feature maps to its own open issue, grant and approved acceptance criteria.
- [ ] Grant author/repository/branch/target match this PR; every linked issue qualifies.
- [ ] Grant comment, pointer label and original approval strictly preceded this opening/reopening.
- [ ] This is the first author PR opening/reopening after each approval, or the same previously consuming PR.
- [ ] The description contains real closing references outside examples.
- [ ] No unrelated feature or improvement exceeds the approved issue scopes.
- [ ] Necessary tests/docs accompany the behavior; actual results and limitations are reported.

See [CONTRIBUTING.md](../CONTRIBUTING.md) for deployment state, timing, recovery,
independent current-HEAD review and CI requirements. Owner review exemption never
waives grants, open issues, CI or the dedicated App check.
