# Service argument validation (#87)
Objective: migrate all 70 registered commands to declarative structural validation without changing policy precedence.
Branch: davidlig/fix-services-automate-argument-validation-and-co. Delivery: single-pr; maintainer explicitly accepted size exception. Forecast: 3,000–5,000 authored changed lines. Runner: PHPUnit 13; final project-wide 100% coverage once.

## Specs

### S1 — Declarative validation and compatibility
Address this systematically across **all registered commands in NickServ, ChanServ, MemoServ and OperServ**, not only SET.

Introduce declarative structural argument definitions owned by each service's inbound command adapters. Describe the supported variants, ordered arguments, required and optional values, literal alternatives, nested subcommands and arguments consuming the remaining text. Reference the existing localized syntax keys used by HELP; do not parse translated syntax strings.

Use these definitions to validate argument structure and automatically select the appropriate syntax for the recognized variant. Replace duplicated structural checks and hand-selected syntax responses where the mechanism covers them. Keep this a bounded command-validation facility, not a general command framework or a second independent declaration of the same grammar.

Keep HELP ownership local to each service, translation/IRC presentation outside Application and Domain, and business validation in the existing use cases and domain policies. Structural validation must be side-effect-free.

Migrate every registered command, preserving aliases, accepted alternative/legacy forms, valid behavior, unknown-option responses and business errors. Preserve authorization, Root/founder semantics, channel-policy checks, auditing and their existing precedence.

### S2 — Mandatory prevention
Add the requirement to the new-command checklist in `AGENTS.md`, with the detailed contract and tests in `.agents/services.md`:

> Every new or modified service command must declare its argument structure and contextual syntax through the command validation system. Required and optional parameters must match runtime behavior. Include routing-level regression tests before considering the command complete.

Add an automated registry-wide check across all four services that fails when a registered command or declared variant lacks its argument definition or required syntax metadata. Extend existing HELP-alignment coverage for metadata consistency and keep focused runtime tests for actual validation and output.

### S3 — Acceptance
- [x] Audit and migrate every registered command in the four services; record which commands required corrections.
- [x] Recognized options and nested subcommands automatically select their specific localized syntax for structural argument errors.
- [x] Generic syntax accurately represents required/optional parameters; Spanish NickServ SET uses `SET <opción> [valor]`.
- [x] Missing PASSWORD values show `SET PASSWORD <nueva_contraseña>`; bare `SET VHOST` remains valid.
- [x] Preserve appropriate generic or unknown-option responses when a valid variant cannot be identified.
- [x] Cover missing, extra and structurally invalid arguments where applicable, preserving optional values and multiword free text.
- [x] Cover option-first, channel-first and nested layouts through public routing/dispatch interfaces.
- [x] Rejected structural input does not invoke business operations or mutate persisted data; verify using isolated state and suitable test doubles.
- [x] Preserve accepted aliases, legacy forms, valid effects, business errors, permissions, channel policy and audit behavior.
- [x] Automated registry checks detect missing declarations/metadata for future commands; behavioral tests assert actual presented syntax, not merely key presence.
- [x] Update agent rules and necessary localized syntax consistently across supported locales, respecting any separately staged HELP rollout and avoiding unrelated wording changes.
- [x] Follow project test-first discipline, focused checks, architecture checks and final quality gates, including the final 100% coverage gate.

### S4 — Accepted planning decisions
- The user authorized strictly necessary syntax corrections across all 14 locales, without extending the separate Spanish-first HELP descriptions/examples/style preview.
- Do not mass-convert switch to match; there is no measured execution benefit.
- One immutable grammar, pure matching, prefix and contextual checkpoints preserve the current authorization/channel-policy precedence.
- Common validation is narrow Shared/Adapter/In/Irc/CommandArgument code with explicit architecture/Deptrac boundaries.
- No push, PR creation, merge, remote execution, live daemon/database, credential discovery or dependency upgrades.

## Tasks
- T1 [S1,S3,S4] inline — complete, focused checks passed — immutable definitions/matcher, checkpoint tests, architecture layer; commit dee11565. Risk: none (additive core, no existing command consumes it yet).
- T2 [S1,S3,S4] delegated — complete, independently verified; final coverage gate passed — NickServ migration, SET/SASET regressions and syntax translations; commit 0b505814. Risk: items 2,3 (authorization precedence/shared parser).
- T3 [S1,S3,S4] inline — complete, independently verified; final coverage gate passed — MemoServ migration and routing regressions; commit d976eeb5. Risk: items 2,3.
- T4 [S1,S3,S4] delegated — complete, independently verified; final coverage gate passed — ChanServ migration and policy/leaf checkpoint regressions; commit 8afe5e1b. Risk: items 2,3.
- T5 [S1,S3,S4] delegated — complete, independently verified; final coverage gate passed — OperServ migration and nested/legacy regression coverage; commit f18508d3. Risk: items 2,3.
- T6 [S2,S3] inline — complete, independently verified; final coverage gate passed — registry-wide prevention, HELP metadata alignment, agent contract; commit 57ed7f8f. Risk: item 3.
- T7 [S1-S4] delegated verify — complete — full coverage6560tests60758assertions zeroissues;23262/23262exact statements. Independent feature and bounded correction verification passed. Core46a8ceca and service commits above. Trigger: high-risk items2,3 and long full-suite action.

## Log

### L1 — Original implementation request
> Implement the plan.

### L2 — Original scope (issue #87, verbatim)
## Problem

Commands with recognized options can return generic command syntax instead of the syntax of the selected option. Generic syntax can also incorrectly mark a value as mandatory when some options allow omission.

Reported NickServ example:

```text
-> *nickserv* set password
-NickServ- ✗ Sintaxis incorrecta. Uso: SET <opción> <valor>
```

For the recognized PASSWORD option, the response should be:

```text
✗ Sintaxis incorrecta. Uso: SET PASSWORD <nueva_contraseña>
```

For SET without an option, the generic Spanish syntax must instead be:

```text
✗ Sintaxis incorrecta. Uso: SET <opción> [valor]
```

The generic value is optional because some options permit omission. This must not make the value optional for PASSWORD. Bare `SET VHOST` remains valid; its specific syntax is `SET VHOST [vhost]`.

## Requested solution

Address this systematically across **all registered commands in NickServ, ChanServ, MemoServ and OperServ**, not only SET.

Introduce declarative structural argument definitions owned by each service's inbound command adapters. Describe the supported variants, ordered arguments, required and optional values, literal alternatives, nested subcommands and arguments consuming the remaining text. Reference the existing localized syntax keys used by HELP; do not parse translated syntax strings.

Use these definitions to validate argument structure and automatically select the appropriate syntax for the recognized variant. Replace duplicated structural checks and hand-selected syntax responses where the mechanism covers them. Keep this a bounded command-validation facility, not a general command framework or a second independent declaration of the same grammar.

Keep HELP ownership local to each service, translation/IRC presentation outside Application and Domain, and business validation in the existing use cases and domain policies. Structural validation must be side-effect-free.

Migrate every registered command, preserving aliases, accepted alternative/legacy forms, valid behavior, unknown-option responses and business errors. Preserve authorization, Root/founder semantics, channel-policy checks, auditing and their existing precedence.

## Mandatory agent rule and automated prevention

Add the requirement to the new-command checklist in `AGENTS.md`, with the detailed contract and tests in `.agents/services.md`:

> Every new or modified service command must declare its argument structure and contextual syntax through the command validation system. Required and optional parameters must match runtime behavior. Include routing-level regression tests before considering the command complete.

Add an automated registry-wide check across all four services that fails when a registered command or declared variant lacks its argument definition or required syntax metadata. Extend existing HELP-alignment coverage for metadata consistency and keep focused runtime tests for actual validation and output.

## Acceptance criteria

- [ ] Audit and migrate every registered command in the four services; record which commands required corrections.
- [ ] Recognized options and nested subcommands automatically select their specific localized syntax for structural argument errors.
- [ ] Generic syntax accurately represents required/optional parameters; Spanish NickServ SET uses `SET <opción> [valor]`.
- [ ] Missing PASSWORD values show `SET PASSWORD <nueva_contraseña>`; bare `SET VHOST` remains valid.
- [ ] Preserve appropriate generic or unknown-option responses when a valid variant cannot be identified.
- [ ] Cover missing, extra and structurally invalid arguments where applicable, preserving optional values and multiword free text.
- [ ] Cover option-first, channel-first and nested layouts through public routing/dispatch interfaces.
- [ ] Rejected structural input does not invoke business operations or mutate persisted data; verify using isolated state and suitable test doubles.
- [ ] Preserve accepted aliases, legacy forms, valid effects, business errors, permissions, channel policy and audit behavior.
- [ ] Automated registry checks detect missing declarations/metadata for future commands; behavioral tests assert actual presented syntax, not merely key presence.
- [ ] Update agent rules and necessary localized syntax consistently across supported locales, respecting any separately staged HELP rollout and avoiding unrelated wording changes.
- [x] Follow project test-first discipline, focused checks, architecture checks and final quality gates, including the final 100% coverage gate.

## Evidence and related work

Source inspection confirms that NickServ `SetCommand::execute()` rejects missing values with generic syntax before resolving the option, although specific syntax keys already exist. Command contracts expose `getMinArgs()`, `getSyntaxKey()` and `getSubCommandHelp()`, but these metadata do not express complete structural validation.

The four service dispatch paths currently perform generic minimum-argument checks. `ServicesCommandHelpAlignmentTest` covers metadata alignment, not complete runtime validation. Preserve dispatch ordering when introducing the mechanism.

Closed issue #83 addressed the same defect class specifically for OperServ BADMAIL. Treat it as prior focused work and regression coverage, not an unfixed duplicate or a request to redo its presentation changes. Closed #78 and #81 addressed wording rather than the cross-service mechanism requested here.

Evidence is the supplied IRC example and read-only source/catalog inspection. No fresh daemon reproduction or functional suite was run for this issue-only task.

## Alternatives and scope boundary

Consulting HELP is a temporary workaround, not a correction to the error response. Do not alter protocols, business rules or unrelated presentation. This issue records future implementation scope; it does not grant contribution authorization. Follow CONTRIBUTING.md before development.


### L3 — Authorization and environment
User: `hazlo tu con gh`. Grant comment 6406386636 at 2026-10-26T01:45:21Z; pointer at 01:45:24Z; approved at 01:45:27Z. Verified actor561369/admin and exact source repository1167938167/branch binding. RDD globally OFF, unchanged.
Baseline c9fafbce; working tree was clean. Locked install with --no-scripts succeeded; composer.json/lock hashes unchanged; PHPUnit13.4.3 available. No tests yet. Composer scratch /tmp/ares-composer-locked.8mwKYA and locator /tmp/ares-composer-locked-path-for-worker retained for parent cleanup.
Implementation route starts inline; parallel service writers will be used only after common contract freezes and user resolves unavailable configured worker model. Exact edit surfaces must be supplied.

### L4 — T1 RED/GREEN
Focused command `./vendor/bin/phpunit --no-coverage --display-all-issues tests/Shared/Adapter/In/Irc/CommandArgument/CommandArgumentValidatorTest.php`: RED exit2, 9 missing-class errors before implementation; GREEN exit0, 9 tests/48 assertions, no issues. Immutable argument/definition/result and pure prefix/context matcher implemented. Exact/optional/rest/nested/unknown/case-insensitive/legacy-extra paths covered. Shared layer remains adapter-only and excluded from container service discovery.

### L5 — T1 checks and delivery decision
Scoped fixer completed before checks. php -l passed all five new PHP files; scoped PHPStan max exit0; focused directory suite exit0 (9 tests, 48 assertions, zero issues); YAML lint passed the two changed configs. Full Deptrac: 0 violations, 0 uncovered, 0 warnings/errors; container lint passed. Full project coverage and independent feature verification have NOT run; no claim of feature completion. No existing command is wired to the new validator yet.
Async questions remain unanswered: available implementation model (gpt-6.1-sol / gpt-6-luna) and delivery strategy (Feature-branch-chain / Stacked-to-main / Un solo PR con excepción de tamaño). Before the first commit the >400-line forecast requires that choice. Planned independent service writers need the model choice because configured gpt-6.1-luna is unavailable; exact model substitutions must not be guessed. All current changes remain uncommitted.
Scratch created by installation and scoped fixer was cleaned by parent after successful checks. Feature document is git-ignored by existing odd/ rule; explicitly include it in the authorized work-unit commit once delivery choice is resolved.

### L6 — User decisions (verbatim)
Modelo para workers: gpt-6-luna max
Entrega: Un solo PR con excepción de tamaño

Delivery is single-pr with the accepted size exception, not a chain. No push/PR creation authorized. Worker override is gpt-6-luna/max. T2/T4/T5 use parallel bounded writers on disjoint service adapter/test/catalog surfaces after the additive core is frozen; parent owns Shared/config/docs/registry and T3. Independent verification remains at feature end for integrated risk items2,3. T1 actual diff is additive and unused by existing commands, so Risk:none; architecture additions permit only the new layer and remove no prior rules.

### L7 — Core work unit
Committed dee11565 feat(irc): add pure service argument validation grammar (379 authored additions including task document). RDD off => disabled/unmanaged, no native review started. Parallel worker units T2 NickServ, T4 ChanServ and T5 OperServ launched with gpt-6-luna/max, disjoint service adapter/test/catalog surfaces. Parent T3 MemoServ and T6 global prevention.

### L8 — Migrated command inventory and correction classes
NickServ (24): DROP, FORBID, FORBIDVHOST, HELP, HISTORY, IDENTIFY, INFO, LIST, NOEXPIRE, RECOVER, REGISTER, REGMODE, RENAME, RESEND, RESTORE, SASET, SET, STATUS, SUSPEND, UNFORBID, UNSUSPEND, USERIP, VERIFY, WHOIP. Structural-check migrations/corrections in SET/SASET, HISTORY/FORBIDVHOST, LIST/NOEXPIRE/REGMODE/SUSPEND/WHOIP; the other commands needed declarations/metadata projection. SET/SASET generic optional values corrected in all14 locales.
MemoServ (8): DEL, DISABLE, ENABLE, HELP, IGNORE, LIST, READ, SEND. Existing structural checks moved into declarations for DEL/READ numeric indices and optional channel, SEND rest text, IGNORE variants/conditional channel; other commands needed metadata/prefix migration only. No locale changes needed. Public routing replies/mutation interaction assertions match c9fafbce: 6tests/13assertions on both versions in /tmp/memoserv-argument-diff.erl0lgf1.
OperServ (9): BADMAIL, GLINE, GLOBAL, HELP, IRCOP, KILL, MOTD, RAW, ROLE. Structural variants/corrections in GLINE/MOTD/IRCOP/ROLE; BADMAIL preserves #83 output and FILTER_VALIDATE_INT signed/leading-zero semantics. Generic/nested syntax corrected only where necessary in all14 locales. Selected differential probes match: BADMAIL5/35, IRCOP5/12, ROLE28/131.
ChanServ (29, including manual rank commands): all runtime registry entries have definitions. ACCESS/AKICK/LEVELS/HISTORY/SET and manual-rank target checks migrated; other commands needed declaration/projection only. No catalog wording changes needed. Existing setting resolution, founder/channel policy and audit order retained. See registry gate for canonical command names and all HELP variant syntax coverage.

### L9 — Compatibility corrections and local proof
Parent spot-check found an unauthorized SET MSG/PRIVATE value-policy and EMAIL-token reclassification plus erased invalid-IP/business-outcome test. One correction batch restored semantic ownership and original test, made SET/SASET values rest tails, and derived HELP/unknown-option lists from variants. New tests pin TokenRequired/invalid mode/SASET Root precedence; focused16/92 and NickServ adapter541/4007 passed. NickServ baseline/candidate public probes matched prior accepted/business outcomes; only missing-value contextual syntax differs as requested.
Shared grammar gained conditional prefixed tokens, opt-in digits/nonBlank, unknown fallback and canonical legacy token permutations based on concrete MemoServ/NickServ/OperServ cases, each with observed missing-parameter RED then GREEN. Shared13tests/73assertions. No generic value-policy validation added.
MemoServ suite126/1665; ChanServ772/6901; OperServ248/3039 passed. ChanServ base764/6876; own focused250/772. Base test runs alone are not claimed as full differential proof. Source ownership checked: writers touched only their service adapter/test/catalog surfaces; parent adapted the OperServ event-listener fake outside its writer surface.

### L10 — Integrated checks and assessment
Final source normalizer ran before integration checks (7 intended test files fixed). Combined adapter/core/registry/event seam suite:1714tests/20027assertions, zero issues. Parent scoped PHPStan max passed; Deptrac0violations/0uncovered/0warnings/errors. Registry gate5tests/4296assertions covers all70 definitions, each nested syntax in14locales, HELP-reference consistency, aliases and deliberately missing root/variant metadata. Replaced obsolete translated-English-braces parser with this definition-based alignment, not a second translated grammar.
Native review assess initially refused undeclared untracked files; parent staged exactly the4 intended new files, reassessed high/item3 successfully (currentchanges160paths/3873lines at assessment). RDD remains globally OFF; no native review STATUS/START or consent invoked. Independent functional verifier for items2,3 follows own checks; final global coverage has not run yet.

### L11 — Resumed session and immutable candidate check
User requested continuation from the historical Orca transcript, read-only. Workspace source/test/catalog bytes matched the previously verified frozen candidate exactly. Memory observations 4427/4428 and transcript final verifier handoff confirm independent verification completed: public Spanish syntax probes, baseline/candidate comparison, and seeded fake-state digest equality with zero mutator calls. Parent registry spot check passed 5 tests/4308 assertions. No historical provider session was resumed or edited. RDD remains globally off.

### L12 — First final global gates and one bounded correction
Final global run: PHP lint129files PASS; container PASS; YAML127files PASS; Deptrac0violations/0uncovered PASS; fixer dry-run2078files PASS. PHPStan exposed17test typing errors. The single full coverage invocation ran6552tests60681assertions with2architecture failures,23267/23279covered statements (99.94845139%), not a passing gate. The architecture test still disallowed the explicitly approved Shared adapter grammar; it now allowlists exactly its4files. Test helpers now declare positional lists and narrow translator values to strings without suppressions.
Added direct Application handler tests for partial ACCESS/AKICK/LEVELS input with no persistence/event/network effects; retained all Application guards. Removed unreachable duplicate LEVELS/MOTD presenter paths, made SUSPEND rest reason nonblank in its grammar with whitespace/no-effects coverage, and derived BADMAIL parse-error syntax from variants rather than a second mapping. Added unknown/blank BADMAIL input checks and missing MOTD-ID handler-not-called checks. Committed-regression candidate now includes the prior verifier's seeded-state digest probe, independent of git history or environment.
Correction normalization completed before checks. Focused coverage:199tests8813assertions zeroissues; targeted Application handlers and NickServ SUSPEND/MOTD fully covered, original missing handler guard lines covered. This is not a replacement claim for a successful full coverage gate. Full PHPStan now0errors; independent bounded correction recheck105tests8598assertions PASS, frozen prior command batch82tests251assertions PASS. No new blocker reproduced. Parent final spot check204tests13121assertions PASS; container/YAML/Deptrac/fixer rechecks PASS.
The exactly-once full coverage budget has been consumed; no full rerun was performed. T7 remains incomplete pending explicit authorization to repeat the final coverage gate after corrections. No push, PR, remote operation or native review was performed. Original global-gate logs: /tmp/tmp.ZexbwJeWZw; focused coverage/log: /tmp/issue87-focused-clover.xml and /tmp/issue87-focused-coverage.log. Scratch remains retained while this final-proof decision is pending.

### L13 — Local work units and delivery state
Local commits: 46a8ceca Shared grammar extensions/exact architecture allowlist; 0b505814 NickServ; d976eeb5 MemoServ; 8afe5e1b ChanServ plus application guard tests; f18508d3 OperServ; 57ed7f8f registry-wide prevention and agent rules. Each service commit includes its regression tests and relevant syntax catalogs; the accepted one-PR size exception remains in effect. Rollback boundaries are each service's inbound adapters/tests/catalogs; core and prevention own separate commits. Earlier focused suite and runtime routing evidence is recorded in L8-L12; runtime daemon N/A because live/remote operations are outside authorization. All commits are disabled/unmanaged under the globally off native review switch; no review START or consent was run.
Final parent checks: PHP syntax147modified/new files PASS; scoped/core13tests73assertions PASS; correction/architecture/registry204tests13121assertions PASS; PHPStanmax0errors PASS; container PASS; YAML127files PASS; Deptrac0violations/0uncovered PASS; fixer dry-run0/2082files PASS; git diff --check PASS. The first full coverage result is still failed historical proof, not replaced by focused tests or merged coverage claims. Required next decision: authorize one renewed full coverage run after corrections; until that proof is observed T7 and final feature acceptance remain incomplete. No changes to composer dependencies or business-use-case production code.

### L14 — Renewed final coverage authorization
User (verbatim): `si`
The user explicitly authorized one renewed `./scripts/check-coverage.sh 100 --issues` invocation after the bounded corrections. No other authorization or delivery scope changes. T7 resumes for this single final proof run; previous failed coverage evidence is retained.

### L15 — Renewed final coverage PASS and closure
Executed the explicitly renewed `./scripts/check-coverage.sh 100 --issues` exactly once: exit0,6560tests60758assertions,zero failures/errors/warnings/notices/skipped/incomplete/risky/deprecations. Clover exact equality:23262coveredstatements/23262statements. Parent read back Clover metrics and log and reran registry alignment:5tests4308assertions PASS; git diff --check PASS. No source/test/config changes during the run. The previous failed invocation remains historical evidence, now followed by successful renewed proof; no thresholds, source filters, exclusions or dependency versions changed. All S1-S4/T1-T7 outcomes now have observed proof; no failed, skipped or pending required check remains. Native review remains globally off, disabled/unmanaged; live daemon/DB/remote testing remains outside authorized scope. No push/PR/merge performed.
Final generated coverage remains in ignored var/coverage/clover.xml. Used external verifier/test scratch is removed by the parent after evidence capture; no source scratch was left in the worktree. Delivery remains one future PR with the accepted size exception, publication not authorized.
