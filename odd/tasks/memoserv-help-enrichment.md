# Feature: MemoServ Help Enrichment

**Objective:** Enrich the per-command HELP of every user-visible MemoServ command with the
ChanServ/CRegServ pattern: parameter notes + a dynamic example line via `getExampleKey()`,
rendered by `MemoServ\Adapter\In\Irc\Help\UnifiedHelpFormatter::renderCommandHelp`.
**Branch:** `feat/memoserv-help-enrichment` (from `main` @ `50caf115`).
**Delivery strategy:** `ask-on-risk` — decide single PR vs chain when T4 forecasts size.
**Test runner:** `php vendor/bin/phpunit` (exec bits lost in the Windows→Linux sync; run via `php`).

## Specs

**S1 — Reference material (user-provided, verbatim).** The ChatHispano `MeMO` help dump is the
tone/richness reference. Ares rules always win over ChatHispano semantics; the dump is not a
string source.

```
Session Start: Sun Oct 18 00:23:19 2026
Session Ident: MeMO
00:23 Session Ident: MeMO (-@bots.chathispano.com)
00:23 <MeMO> 3MeMO - 7ChatHispano
00:23 <MeMO>  
00:23 <MeMO> 3MeMO es un robot que permite que los usuarios se envíen mensajes entre ellos.
00:23 <MeMO>  
00:23 <MeMO> Comandos disponibles:
00:23 <MeMO>   12SEND      Envía un mensaje a un usuario
00:23 <MeMO>  12IGNORE     Administra la lista de usuarios ignorados
00:23 <MeMO>   12ENABLE    Activa el servicio
00:23 <MeMO>   12DISABLE   Desactiva el servicio
00:23 <MeMO>  
00:23 <MeMO> FIN de la AYUDA de 3MeMO
Session Close: Sun Oct 18 06:37:58 2026
```

**S2 — Formatter contract (mirror of ChanServ).** `renderCommandHelp` renders the example block
only when the handler declares `getExampleKey()` (`method_exists` contract) and it returns a
string; the block is `help.example_label` with `%example%`, placed after the syntax line and
before the footer. Handlers without the hook keep their current output byte-for-byte. Implemented
in MemoServ's OWN copy of the formatter — no cross-service import, no shared base (protocol
isolation / controlled duplication).

**S3 — Scope.** In scope: the 7 user-visible commands — SEND, LIST, READ, DEL, IGNORE, ENABLE,
DISABLE. Each declares `getExampleKey()` and, where a dynamic placeholder adds value,
`getHelpParams()` (already supported by the formatter at `UnifiedHelpFormatter.php:115`).
OUT of scope: HELP (documents itself; ChanServ precedent), IGNORE sub-option examples
(`renderSubCommandHelp` has no example support in any copy), every behavior change.

**S4 — Locale coverage.** Every new user-visible string exists in all 14 locales
(`ca de el en es eu fr gl it nl pl pt ro tr`): `help.example_label` plus each command's
`example` key.

**S5 — Dynamic nick.** Examples use `%bot%` (resolved by `MemoServContext` placeholders via
`ServiceNicknameRegistry::getAllPlaceholders()`), never a hardcoded service nickname.

**S6 — No behavior change.** Adapter + translations + tests only. Domain and Application
untouched.

**S7 — Tests.** `UnifiedHelpFormatterTest` (or MemoServ equivalent) proves the example block
renders when declared and is absent otherwise; `HelpCommandTest` integration asserts the same
end-to-end. Zero failures/warnings/notices/skips/incomplete/risky/deprecations.

**S8 — Quality gates.** `php -l`, `lint:container`, `lint:yaml`, PHPStan `--level=max`
(0 errors), PHP-CS-Fixer, Deptrac (0 warnings / 0 errors), and
`./scripts/check-coverage.sh 100 --issues` run exactly once at the end, requiring exact 100%
line coverage (equality of covered/total statements, not a rounded display).

## Tasks

- **T1** (S2, S8) — Formatter: add the example block to MemoServ's `renderCommandHelp`; add
  `help.example_label` to `translations/memoserv.en.yaml`. Route: inline. Status: pending.
- **T2** (S3, S5) — 7 commands declare `getExampleKey()` (+ `getHelpParams()` where needed);
  English example strings with `%bot%`. Route: inline. Status: pending.
- **T3** (S4, S5) — Translate `help.example_label` + the 7 `example` keys across the 13
  remaining locales. Route: delegated (context reason — ~112 mechanical strings across 13
  files). Status: pending.
- **T4** (S7, S8) — Tests (formatter + HelpCommand integration) + full gate chain + coverage
  100 exactly once. Route: inline + gate. Status: pending.

## Log

- **L1 (2026-10-18)** — User request, verbatim: "Que otro(s) textos quieres para enrriquecer
  Ares? (P.D: No tengo acceso a OperServ, ni a comandos de IRCop e ChatHipano)", followed by
  the MeMO help dump shown in S1. No oper-only surface available; MemoServ chosen as the next
  user-visible service.
- **L2 (2026-10-18)** — User authorized the feature: "Si" to "¿Arrancamos con MemoServ
  basándonos en este estilo?".
- **L3 (2026-10-19)** — User: "Dale" — proceed.
- **L4** — Reconnaissance evidence (this machine, `/opt/ares`): 7 command files under
  `src/MemoServ/Adapter/In/Irc/Command/`; `getHelpParams()` already supported at
  `UnifiedHelpFormatter.php:115`; zero `getExampleKey` matches in `src/MemoServ`; zero
  `example:` keys and zero `example_label` across all 14 `translations/memoserv.*.yaml`;
  `MemoServContext.php:128` injects `%bot%` placeholders via `getAllPlaceholders()`; IGNORE
  exposes 3 sub-options (ADD/DEL/LIST) through `getSubCommandHelp()`.
