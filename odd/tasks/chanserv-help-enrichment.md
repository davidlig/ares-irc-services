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
- [x] T1 — DONE (commit 228f5290, feat/chanserv-help-enrichment): example block ported
      from CRegServ copy; 2 tests added (with/without getExampleKey); spot-check re-run:
      `phpunit tests/ChanServ/.../UnifiedHelpFormatterTest.php --no-coverage` → OK
      (10 tests, 4732 assertions).
- [x] T2 — DONE (commit 26dc026f): getExampleKey() on the 16 user-visible commands
      (isOperOnly()===false, HelpCommand excluded; oper-only verified per class);
      16 example keys + help.example_label in chanserv.en.yaml; 16 unit tests.
      Deviation accepted: examples use %bot% placeholder (catalog guard forbids
      literal service nicks; %bot% is injected by ChanServContext::wrapParams).
      Spot-check re-run: `phpunit tests/ChanServ/Adapter/In/Irc/Command --no-coverage`
      → OK (607 tests, 1428 assertions). Catalog test RED = known-pending until T3.
- [x] T3 — DONE (commit 98d14e14): 221 keys (17×13 locales) propagated; catalog test
      green. Spot-check re-run: `phpunit tests/Shared/Translations/TranslationCatalogTest.php
      --no-coverage` → OK (11 tests, 34695 assertions). Locale judgment calls: localized
      usage tokens per file (es #canal/apodo, pl #kanał, eu #kanala…), duration units
      per file convention (fr 7j, tr 7g, resto 7d), register.example → literal CReG.
- [x] T4 — DONE (2026-10-18, /opt/ares): HelpCommandTest integration assertions —
      `oneArgShowsCommandHelpForKnownCommand` asserts the example line is PRESENT for a
      handler with `getExampleKey()` (`help.example_label` + `register.example`), and
      `oneArgOmitsExampleLineWhenHandlerLacksExampleKey` asserts it is ABSENT for a handler
      without it. Also hardened T2: `ManualRankCommandsTest` fails if a manual-rank command
      lacks `getExampleKey()`. Full gate chain green (2026-10-18):
      `php -l` OK (2 files); `lint:container` OK; `lint:yaml` OK (139 files);
      PHPStan max 0 errors; CS-Fixer fixed 1 file (added missing `use function sprintf`);
      Deptrac 0 warnings / 0 errors;
      `./scripts/check-coverage.sh 100 --issues` → Coverage 100.00% (22849/22849 lines),
      full suite green (zero failures/warnings/notices/skips/incomplete/risky/deprecations).
      Route: parent + focused writer (same session).

## Progress log

- 2026-10-18: plan created; exploration done (map in session); environment check: PHP missing on Windows.

## Delivery strategy

Single feature branch `feat/chanserv-help-enrichment`. Forecast ≈ 600 authored lines
(exceeds the 400-line advisory) — PR/chain decision belongs to the user; no PR without
explicit request.
