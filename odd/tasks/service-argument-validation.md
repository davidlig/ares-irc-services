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
- [ ] Follow project test-first discipline, focused checks, architecture checks and final quality gates, including the final 100% coverage gate.

### S4 — Accepted planning decisions
- The user authorized strictly necessary syntax corrections across all 14 locales, without extending the separate Spanish-first HELP descriptions/examples/style preview.
- Do not mass-convert switch to match; there is no measured execution benefit.
- One immutable grammar, pure matching, prefix and contextual checkpoints preserve the current authorization/channel-policy precedence.
- Common validation is narrow Shared/Adapter/In/Irc/CommandArgument code with explicit architecture/Deptrac boundaries.
- No push, PR creation, merge, remote execution, live daemon/database, credential discovery or dependency upgrades.

## Tasks
- T1 [S1,S3,S4] inline — complete, focused checks passed; commit pending — immutable definitions/matcher, checkpoint tests, architecture layer; commit pending. Risk: none (additive core, no existing command consumes it yet).
- T2 [S1,S3,S4] delegated — in progress — NickServ migration, SET/SASET regressions and syntax translations; commit pending. Risk: items 2,3 (authorization precedence/shared parser).
- T3 [S1,S3,S4] inline — pending — MemoServ migration and routing regressions; commit pending. Risk: items 2,3.
- T4 [S1,S3,S4] delegated — in progress — ChanServ migration and policy/leaf checkpoint regressions; commit pending. Risk: items 2,3.
- T5 [S1,S3,S4] delegated — in progress — OperServ migration and nested/legacy regression coverage; commit pending. Risk: items 2,3.
- T6 [S2,S3] inline — pending — registry-wide prevention, HELP metadata alignment, agent contract; commit pending. Risk: item 3.
- T7 [S1-S4] delegated verify — pending — final normalization/gates, differential verification and independent risk verifier; commit pending. Trigger: high-risk items 2,3 and long full-suite action.

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
- [ ] Follow project test-first discipline, focused checks, architecture checks and final quality gates, including the final 100% coverage gate.

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
