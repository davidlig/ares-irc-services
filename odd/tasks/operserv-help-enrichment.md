# OperServ HELP enrichment

Objective: give every OperServ command and sub-command its canonical example line, completing the
enrichment the other services already have, and close the KILL duplication that opened this branch.
Branch: `fix/operserv-kill-help` (base `50caf115`), extended into one OperServ unit.
Delivery strategy: single PR (#40), same shape as the other services; the CReServ payload travels
with it because the branch is based on the fork's `main`.
Test runner: `php vendor/bin/phpunit` (focused, no coverage during development; coverage gate once at
the end via `./scripts/check-coverage.sh 100 --issues`).

## Specs

- S1 — Every OperServ command except `HELP` declares `getExampleKey()`, and every one of its
  user-visible sub-pages declares an `example_key` in its `getSubCommandHelp()` entry. All of them
  exist as `example` keys in the 14 locales.
- S2 — `renderSubCommandHelp()` gains the same example block `renderCommandHelp()` already has
  (`method_exists`-free: the sub entry is an array, so `isset($sub['example_key'])`), between the
  syntax label and the footer. `renderCommandHelp()` keeps the block delivered in `64c68c30`.
- S3 — Every example is a realistic, ready-to-type line in the house form `/msg %bot% <CMD> [args]`,
  derived from that command's own `syntax` value. `%bot%` must be the only placeholder and the
  placeholder set must be identical across the 14 locales.
- S4 — No prose is rewritten and no text is copied from another implementation. Pulsar's OperServ was
  read as a style reference only: its command set (KILLCLONES, JAIL, TAKEOVER, SVSNICK, HACK, BLOCK)
  barely intersects Ares's, and where it does (KILL, GLOBAL) its text is one `Uso:` line — thinner
  than Ares's current description, which already states permissions, formats and restrictions.
- S5 — `HELP` itself stays without an example (it documents itself, as in every other service).
  Protected/hard-coded values are not invented as examples where the command rejects them.
- S6 — Gates: `tests/OperServ` and `tests/Shared` green, `lint:container`, `lint:yaml`, PHPStan max
  0 errors, Deptrac 0 warnings / 0 errors, CS-Fixer clean, and `check-coverage.sh 100` at 100.00%.

## Tasks

- [ ] T1 — Formatter: `renderSubCommandHelp()` example block for OperServ.
- [ ] T2 — The 7 top-level commands without an example: `EMAILVERIFY`, `GLINE`, `GLOBAL`, `IRCOP`,
      `MOTD`, `RAW`, `ROLE` — `getExampleKey()` + `example` × 14 locales + per-command regression.
- [ ] T3 — The 20 sub-pages: `EMAILVERIFY` (on/off/status), `GLINE` (add/del/list), `IRCOP`
      (add/del/list), `MOTD` (add/del/list/clean), `ROLE` (add/del/list/perms/modes/vhost/operclass)
      — `example_key` in the arrays + `example` × 14 locales + formatter and end-to-end assertions.
- [ ] T4 — Delivery: one commit on the branch, push, and update issue `#39` / PR `#40` so their title
      and body describe the full OperServ unit instead of only the KILL fix.

## Log

- L1 — User's original request for this unit, verbatim: "Para enriquecer OperServ, podrías fijarte en
  como lo tiene pulsar".
- L2 — 2026-10-19: Pulsar's OperServ was inspected (`modules/operserv/main.c`, `lang/es.l`
  `OPER_HELP_*`). Findings: its command set is much larger and different (KILLCLONES, JAIL,
  TAKEOVER, SVSNICK, SVSPART, EXTERMINATE, HACK, BLOCK, CLEARMODES, SERVICEMAP…), its style is
  `Sintaxis:` + `Uso:` + optional paragraphs, and the two commands that also exist in Ares are
  described in one line each (`KILL`: "Expulsa a un usuario del servidor"; `GLOBAL`: "Envía un
  mensaje a todos los usuarios del IRC"). Ares's own descriptions are already richer, so the
  reference contributes style, not content, and nothing is copied.
- L3 — 2026-10-19: scope fixed by inspection: 7 top-level commands have no example, and 20 sub-pages
  exist across EMAILVERIFY, GLINE, IRCOP, MOTD and ROLE. `KILL` is already done in `64c68c30`.
