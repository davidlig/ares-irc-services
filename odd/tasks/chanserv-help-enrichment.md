# Feature: ChanServ Help Enrichment

## Objective

Enrich the per-command HELP of every user-visible ChanServ command with the CRegServ
pattern: parameter notes + a dynamic example line via `getExampleKey()`, rendered by
`UnifiedHelpFormatter::renderCommandHelp`. Reference for tone/richness: the ChatHispano
`CHaN` help dump (stored in Engram obs #482 and pasted in session); ares rules always win
over ChatHispano semantics.

## Problem / Why

Ares ChanServ already uses UnifiedHelpFormatter (grouped general help), but per-command
help is thin: syntax + short text, no parameter notes, no examples. CRegServ proved the
enriched pattern (commits 7aa6f170, 5e05d00d); ChanServ is the next service.

## Scope

IN scope (user-visible commands, mechanical rule: `isOperOnly() === false`):
REGISTER (redirect stub — example optional/pointing to CReG), INFO, SET, ACCESS,
DELACCESS, AKICK, LEVELS, INVITE, VOICE, DEVOICE, OP, DEOP, ADMIN, DEADMIN, HALFOP,
DEHALFOP (writer confirms the exact list via `isOperOnly()`).

OUT of scope:
- OperServ and every oper-only / IrcopAuditable command (no oper access on the reference net).
- ChatHispano commands that do not exist in ares (IDENTIFY, UNSET, WHITELIST, CLEAR as a
  single command, DELSUCCESSOR, UNBAN, TOPIC) — help cannot document nonexistent commands.
- Examples for sub-options (`renderSubCommandHelp` has no example support in any copy;
  extending it would be speculative scope — Karpathy principle).
- Any change to command behavior; this is help/translations/tests only.

## Constraints

- Texts: infinitive neutral Spanish register as today (NO voseo, NO tú imperatives in
  command descriptions); translations domain `chanserv`, 14 locales
  (ca de el en es eu fr gl it nl pl pt ro tr).
- Pattern fidelity: port the example block from
  `src/CRegServ/Adapter/In/Irc/Help/UnifiedHelpFormatter.php:171-175` into the ChanServ
  copy; `getExampleKey()` returns `'<command_lowercase>.example'`; label key
  `help.example_label` exists in cregserv.*.yaml — add to all chanserv.*.yaml.
- Per-context duplication is house style (Deptrac): edit ONLY the ChanServ copy.
- Tests first (RED → GREEN), 100% line coverage gate, PHPStan max, CS-Fixer, Deptrac.
- Environment blocker: no PHP on this Windows machine (verified: `php` not on PATH,
  no vendor phpunit shim) — gates cannot run until T0 resolves it.

## Acceptance criteria

- `HELP <command>` on any user-visible ChanServ command ends with the
  `help.example_label` line containing a realistic `/msg CHaN …` example.
- All 14 locales define every new `*.example` key + `help.example_label`
  (TranslationCatalogTest green).
- Formatter unit test asserts the example block renders (and stays absent when the
  handler lacks `getExampleKey()`).
- All quality gates run green (evidence recorded per task).

## Tasks

- [x] T0 — PHP toolchain on Windows DONE (2026-10-18): scoop PHP 8.5.11 + exts
      (mbstring/pdo_mysql/pdo_sqlite/curl/zip/openssl) + xdebug 3.5.3 + composer 2.10.3;
      vendor refreshed; PHPUnit 13.4.1 / PHPStan 2.3.1 / CS-Fixer 3.95.27 verified.
      NOTE: this opencode session predates the scoop env vars — every php invocation in
      this session needs:
      `$env:PHP_INI_SCAN_DIR = "C:\Users\juanj\scoop\apps\php\current\cli;C:\Users\juanj\scoop\apps\php\current\cli\conf.d"`
      (new shells/opencode restart inherit it automatically).
- [ ] T1 — Port example-render block into `src/ChanServ/Adapter/In/Irc/Help/UnifiedHelpFormatter.php`
      (RED test first in `tests/ChanServ/Adapter/In/Irc/Help/UnifiedHelpFormatterTest.php`).
      Route: inline (mechanical port, pattern known). Commit: `feat(chanserv): render help examples via getExampleKey`.
- [ ] T2 — Add `getExampleKey()` to every user-visible command + `example:` keys and
      `help.example_label` in `translations/chanserv.en.yaml` + per-command unit tests.
      Route: delegated (1 writer, 15+ files). Commit: `feat(chanserv): enrich user command help with examples (en)`.
- [ ] T3 — Propagate new keys to the 13 remaining locales (neutral register, no voseo).
      Route: delegated (1 writer). Commit: `feat(chanserv): translate enriched help examples to 13 locales`.
- [ ] T4 — HelpCommandTest integration assertion (example line present for a command,
      absent for one without `getExampleKey`) + full gates + coverage 100.
      Route: parent + focused writer if needed.

## Progress log

- 2026-10-18: plan created; exploration done (map in session); environment check: PHP missing on Windows.

## Delivery strategy

Single feature branch `feat/chanserv-help-enrichment`. Forecast ≈ 600 authored lines
(exceeds the 400-line advisory) — PR/chain decision belongs to the user; no PR without
explicit request.
