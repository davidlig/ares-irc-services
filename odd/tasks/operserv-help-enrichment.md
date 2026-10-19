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

- [x] T1 — Formatter: `renderSubCommandHelp()` example block for OperServ. DONE in `1fe72519`.
- [x] T2 — The 7 top-level commands without an example: `EMAILVERIFY`, `GLINE`, `GLOBAL`, `IRCOP`,
      `MOTD`, `RAW`, `ROLE` — `getExampleKey()` + `example` × 14 locales + per-command regression.
      DONE in `1fe72519`.
- [x] T3 — The 20 sub-pages: `EMAILVERIFY` (on/off/status), `GLINE` (add/del/list), `IRCOP`
      (add/del/list), `MOTD` (add/del/list/clean), `ROLE` (add/del/list/perms/modes/vhost/operclass)
      — `example_key` in the arrays + `example` × 14 locales + formatter and end-to-end assertions.
      DONE in `1fe72519`: 378 values over 14 locales, all with the house prefix and `%bot%` as their
      only placeholder; no `help` prose changed and the two pre-existing `example` strings
      (`role.vhost.view`, `gline.search.options`) are untouched.
- [x] T4 — Delivery: one commit on the branch, push, and update issue `#39` / PR `#40` so their title
      and body describe the full OperServ unit instead of only the KILL fix. DONE: both now read
      `feat(operserv): enrich per-command HELP with examples and drop the duplicated KILL prose`.

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
- L4 — 2026-10-19: delivered as `1fe72519` (32 files, +614/−13) on top of `64c68c30`, pushed to the
  fork; issue `#39` and PR `#40` retitled and rewritten to describe the whole OperServ unit. Parent
  verification: 378 example values over the 14 locales (27 keys each), every one with the house
  prefix and `%bot%` as its only placeholder; `HELP` has none; no `help` prose changed against
  `64c68c30`; the two pre-existing `example` strings stay byte-identical. Gates: `tests/OperServ`
  834/4624, `tests/Shared` 49/36284, `lint:container` and `lint:yaml` OK, PHPStan max 0, Deptrac
  0/0/0, CS-Fixer 0/2136, coverage 100.00% (22847/22847). Two false alarms from the parent's own
  detectors are worth remembering: counting every key named `example` picks up pre-existing
  non-command strings, and comparing a localized value against its English text looks like a change.
- L5 — 2026-10-19: dbots (`/home/juanjo/irc/sitiochat/dbots`, the ChatHispano mIRC bot system) was
  reviewed as a text reference. Its 11 `helps/*.help` files are terse: `Sintaxis:` plus one line of
  description, with 6 "ejemplo" mentions in the whole set, so they add nothing to the wording and
  nothing was taken from them. Two observations worth keeping: dbots groups its OperServ index by
  privilege tier (Pre-Operadores / Operadores / Administradores / ROOT), which Ares does not need
  because its index is already filtered by permission, so a non-Root oper only sees what they can
  run; and its command inventory is the one ChatHispano users have today, with several commands that
  have no Ares counterpart (REGISTRA, ESTADO, SENDPASS, SENDNEWPASS, blackmail, validar,
  vreg, MIGRAR, APODERA, killclones, listclones, spam, dropnbd, the CeNTeR/NO/PreOpServ/GlobalServ
  modules). That gap is a migration-coverage question for the maintainer, not a help-text task.
