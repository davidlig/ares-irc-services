# NickServ HELP enrichment + legacy correction

Objective: bring NickServ's per-command HELP to the enriched pattern (structured `Syntax:` and
`Example:` blocks, no duplicated prose) in the 14 locales, correcting the legacy presentation the
other services already dropped.
Branch: `feat/nickserv-help-enrichment` (from `50caf115`, the fork's `main` — same base as the
ChanServ/MemoServ branches).
Delivery strategy: single branch, cross-repo PR against `davidlig:main`, same shape the user chose
for ChanServ (`#34`); the PR/chain decision for >400 authored lines belongs to the user at delivery.
Test runner: `php vendor/bin/phpunit` (focused, no coverage during development; coverage gate once
at the end via `./scripts/check-coverage.sh 100 --issues`).

## Specs

- S1 — No NickServ command's localized `help` value re-states the syntax or carries an ad-hoc
  `Examples:`/`Example:` list. The syntax comes from the rendered `help.syntax_label` block and the
  example from the command's own `example` key: one source per fact.
- S2 — `NickServ\Adapter\In\Irc\Help\UnifiedHelpFormatter::renderCommandHelp()` gains the example
  block, ported from `CRegServ`'s copy (the only service carrying it on this base), detected with
  `method_exists($handler, 'getExampleKey')` + `is_string()`. `HelpableCommandInterface` and the
  runtime wiring stay unchanged.
- S3 — Every NickServ command except `HELP` (excluded by convention in all four formatters) declares
  `getExampleKey()` and defines the matching `example` key in all 14 locales
  (`ca de el en es eu fr gl it nl pl pt ro tr`). Oper-only commands are included
  (`NOEXPIRE`, `SASET`, `SAREGISTER`) — the service must not ship two help styles.
- S4 — Every example value is a realistic, ready-to-type line in the house form
  `/msg %bot% <CMD> [args]`. `%bot%` is required: `NickServContext::wrapParams()` injects it for all
  translations (`src/NickServ/Adapter/In/Irc/NickServContext.php:170`), and hardcoded service nicks
  are rejected by the catalog guard, which scans `NickServ`.
- S5 — `help.example_label` exists in all 14 `translations/nickserv.*.yaml`, in the approved palette
  (`\x0303` label color with reset), matching the CRegServ value.
- S6 — Sub-command pages are OUT of this unit: `renderSubCommandHelp()` renders no example block in
  any of the four services, so the `SET`/`SASET` option pages and the `FORBIDVHOST`/`HISTORY`
  sub-command pages keep their current inline text. Documented deviation; reported as a follow-up
  candidate, never silently shipped as "done".
- S7 — The `Subcommands:` prose list inside `forbidvhost.help` is kept, mirroring the ChanServ
  `history` decision (it restates the rendered options block; a separate verbosity choice).
- S8 — Gates: `TranslationCatalogTest` green (key parity, placeholder parity, no dead keys),
  `ServicesCommandHelpAlignmentTest` untouched (it asserts exactly 70 commands and is
  example-agnostic), focused `tests/NickServ` green, PHPStan max 0 errors, CS-Fixer 0 files,
  `lint:yaml`/`lint:container` OK, `check-coverage.sh 100` at 100.00%.
- S9 — Examples are canonical and adapted, not copied: Pulsar's NickServ help
  (`/home/juanjo/irc/cosas de irc/pulsar`, `lang/es.l:2610-3211`) is the richness/wording reference;
  Ares's own `syntax` keys, permissions and semantics decide the final text, and Ares's palette and
  14-locale rule win over the reference.

## Tasks

- [x] T1 — Formatter port + label key. `renderCommandHelp()` gains the example block (CRegServ
      template), `help.example_label` added to the 14 `nickserv.*.yaml`, palette map in
      `tests/NickServ/Adapter/In/Irc/Help/UnifiedHelpFormatterTest.php` updated
      (`'example_label' => ['03']`), formatter tests for present/absent example. Route: inline.
      DONE in `c70bbb2d`: the block is the CRegServ copy detected with `method_exists` + `is_string`,
      and every locale's label is byte-identical to the matching `cregserv.<locale>.yaml` value, so
      the four services agree on the palette.
- [x] T2 — The 12 commands with no example at all: `REGISTER`, `VERIFY`, `RESEND`, `RECOVER`,
      `IDENTIFY`, `STATUS`, `INFO`, `SET`, `LIST`, `RESTORE`, `WHOIP`, `SAREGISTER` —
      `getExampleKey()` + `example` key × 14 locales + per-command `getExampleKeyReturnsCorrectKey`
      regression. Route: one writer. DONE in `c70bbb2d`: 40 files, +445 lines.
- [x] T3 — The 11 commands carrying the legacy prose block: `SASET`, `USERIP`, `SUSPEND`,
      `UNSUSPEND`, `RENAME`, `DROP`, `FORBID`, `UNFORBID`, `FORBIDVHOST`, `HISTORY`, `NOEXPIRE` —
      the labelled legacy block is deleted from `help` in the 14 locales and replaced one-for-one by
      the structured `example` key; `getExampleKey()` added; regressions. Route: one writer.
      DONE in `9d34aac7`: 37 files, +552/−648. Proof of the deletion is a line-level diff against
      `c70bbb2d` (multiset): every removed line outside `help` prose is either blank, a label line,
      or an example invocation, and nothing was added inside any `help` value.
- [x] T4 — Integration: `HelpCommandTest` asserts the example line is present for a command that
      declares the key and absent for one that does not, including an IRCop-gated command rendered
      with an identified sender + operator-access stub (`HelpFormatterContextAdapter:107-109,154`).
      DONE in `9d34aac7`.
- [x] T5 — Delivery: work-unit commits, push to the fork, issue + PR against `davidlig:main`
      (issue-first), correction note in the PR body, labels requested (a human must apply them).
      DONE: issue `#37` and PR `#38` on `davidlig/ares-irc-services`, both carrying the scope guard
      that names the option and sub-command pages as a follow-up decision rather than as done. The
      labels (`type:feature`, `status:approved`, `size:exception`) cannot be applied by this account:
      it has no triage permission on the upstream repository, so a maintainer must set them.

## Log

- L1 — User's original request for this unit, verbatim: "Antes de meterte con NIckServ, revisa la
  estructura, no vaya ser que ya esté enriquecido y querramos que llueva sobre mojado".
- L2 — User's correction mandate, verbatim: "Entonces hay que corregir ese 'fallo' que no vimos y
  corregirt tambien los issues/PR". Go-ahead, verbatim: "Dale".
- L3 — 2026-10-19: reconnaissance (read-only explorer) settled the state on this base.
  `CRegServ` is the only service whose formatter renders the example block; the ChanServ and
  MemoServ equivalents live on their unmerged branches, so NickServ ports from CRegServ.
  `NickServCommandInterface` already mandates `getRequiredPermission()` and `getHelpParams()` for
  every command. 23 of 24 commands need work (`HELP` excluded): 11 carry the legacy prose block
  (worst: `SASET` with per-option legacy text, `HISTORY` and `FORBIDVHOST` with nested sub-command
  pages, `NOEXPIRE`), 12 have no example at all, and `help.example_label` is absent from all 14
  `nickserv.*.yaml`.
- L4 — 2026-10-19: verified before writing: `%bot%` is injected for all NickServ translations
  (`NickServContext.php:170`), so examples may use it; and the palette test caps `04` at exactly two
  occurrences, so `example_label` must use `03` like the sibling label.
- L5 — 2026-10-19: T1+T2 delivered as `c70bbb2d` (40 files, +445). Independent parent verification:
  12 example keys per locale, every value starting with `/msg %bot% `, labels byte-identical to
  `cregserv.*`, the twelve command classes the only `src/` entries in the diff.
- L6 — 2026-10-19: T3+T4 delivered as `9d34aac7` (37 files, +552/−648). Halfway through, a pass
  produced values without the `/msg %bot% ` prefix and had to be corrected: the same failure mode
  appeared in the ChanServ unit. The durable check is mechanical, not visual — count the
  `example` values that do not start with `/msg %bot% ` (must be zero) across all 14 locales.
- L7 — 2026-10-19: the English-only detector was not enough. Legacy labels are localized
  (`Ejemplo:`, `Beispiel:`, `Παράδειγμα:`, `Exemple :`, `Voorbeeld:`), so the residual
  `forbidvhost` wildcard note survived the first sweep in all 14 locales. A YAML-parsing check that
  derives each locale's label word from `cregserv.<locale>.yaml help.example_label` found exactly
  those 14 lines; they were reworded into plain sentences and the detector now reports zero. Gotcha:
  in a YAML double-quoted scalar `\x0303` is one control byte followed by the two characters `03`,
  so the label word must be read before the first control byte, never by stripping `\x` escapes.
- L8 — 2026-10-19: final state of the branch is proven mechanically: 1697 prose lines across the 23
  migrated commands × 14 locales contain no label line and no restatement of the command's own
  `syntax` value; 322/322 example keys exist and carry the house prefix; the line-level diff against
  `c70bbb2d` shows no addition inside any `help` value, so the unit only removed prose. Final gate
  chain, run by the parent on the frozen tree: `lint:container` OK, `lint:yaml` OK (139 files),
  PHPStan max 0 errors, CS-Fixer 0 of 2136 files fixable, `tests/NickServ` 1297 tests / 6672
  assertions, `tests/Shared` 49 / 36144, `check-coverage.sh 100` at 100.00% (22856/22856 lines).
- L9 — 2026-10-19: delivered as issue `#37` + PR `#38` on `davidlig/ares-irc-services`, mirroring the
  ChanServ/MemoServ shape: the PR carries this branch's three commits plus the fork commits upstream
  `main` has not merged. Open decision left with the user: the option pages of SET and SASET and the
  sub-command pages of FORBIDVHOST and HISTORY still restate their syntax above the formatter's
  `Syntax:` line, and giving them a structured example would require extending `renderSubCommandHelp`
  in all four service copies — out of this unit by design, never silently declared done.
