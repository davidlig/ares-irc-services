# VREG and BLACKMAIL in NickServ, EMAILVERIFY out of OperServ

Objective: put the registration-related administrative surfaces back where users expect them. The
global e-mail-verification switch leaves OperServ and becomes NickServ's `VREG`, and NickServ gains
`BLACKMAIL`, the blocked-email-domain list used to keep registrations off disposable or abusive
domains.
Branch: `feat/nickserv-vreg-blackmail` (stacked on `fix/operserv-kill-help`, which carries the
OperServ help unit and its `emailverify` example keys).
Delivery strategy: one PR stacked on #40, same shape as the other units.
Test runner: `php vendor/bin/phpunit` (focused, no coverage during development; coverage gate once at
the end via `./scripts/check-coverage.sh 100 --issues`).

## Specs

- S1 — `EMAILVERIFY` no longer exists anywhere: command, use case, port, adapter, DI wiring,
  translation keys, tests, aliases. Its behaviour (a network-wide switch that makes nickname
  registration require e-mail confirmation) moves to NickServ.
- S2 — NickServ's `VREG {ON|OFF|STATUS}` is oper-only, declared with a new
  `NickServPermission::VREG` in `allIrcop()`, and drives the existing
  `NickServ\Application\Port\In\EmailVerificationPolicyInterface`. It keeps the legacy name users
  know, and it answers in the service where they type it.
- S3 — NickServ's `BLACKMAIL {ADD <domain>|DEL <number|all>|LIST}` manages the list of e-mail domains
  on which no nickname may be registered. It is oper-only with `NickServPermission::BLACKMAIL`, has
  its own persisted list, and `RegisterNickHandler` rejects a blocked domain with a localized reply.
  `SAREGISTER` bypasses the list, because it is staff-forced registration; the legacy package treated
  the list as a user-registration gate, not as a staff restriction.
- S4 — Every new user-visible string exists in the 14 locales (`ca de el es eu gl it nl pl pt ro tr`,
  plus `en`), with catalog key parity and placeholder parity, and the house `/msg %bot% ` example
  form. `%bot%` stays the only placeholder.
- S5 — The registered command count stays 70 after the swap (one out, one in) and becomes 71 with
  `BLACKMAIL`; `ServicesCommandHelpAlignmentTest` is updated exactly once, in the second unit.
- S6 — Gates: `tests/NickServ`, `tests/OperServ` and `tests/Shared` green, `lint:container`,
  `lint:yaml`, PHPStan max 0 errors, Deptrac 0 warnings / 0 errors, CS-Fixer clean,
  `check-coverage.sh 100` at 100.00%.

## Tasks

- [ ] T1 — Swap the command: delete `EMAILVERIFY` and everything only it needs, add NickServ `VREG`
      with its permission, translations, wiring, tests and example. Command count unchanged.
- [ ] T2 — Add `BLACKMAIL`: entity, XML mapping, repository, migration, registration check with its
      outcome and reply, the command with its three sub-pages, permission, translations, tests.
- [ ] T3 — Delivery: work-unit commits, push, issue + PR stacked on #40.

## Log

- L1 — User's request, verbatim: "No, quita EMAILVERIFY de OperServ y añade VREG (como coando) en
  NickServ, demáss del blackmail (si es posible). aparte de esto no se que otros comandos de dbots
  (que aun que sean mIRC nos sirven como referencia para Ares) podemos "robar"".
- L2 — 2026-10-19: what the reference actually does, read from `dbots/helps/ni.help`: `VREG ON|OFF`
  toggles validation for user registration; `BLACKMAIL ADD|DEL|LIST` manages e-mail domains where
  nicknames cannot be registered (`DEL all` clears the list). Neither is an e-mail disclosure tool.
  Ares had no equivalent of `BLACKMAIL` at all, and the `VREG` switch lived in OperServ as
  `EMAILVERIFY` with a `VREG` alias that only answered on the OperServ service, which is the
  placement the user rejected.
- L3 — 2026-10-19: the state already lives in NickServ
  (`EmailVerificationPolicyInterface` plus its setting repository), and OperServ reached it through
  an adapter, so moving the command needs no data migration: only the command, its permission and its
  strings move. `BLACKMAIL` does need new persistence, modelled on `EmailVerificationSetting`
  (entity + XML mapping under `config/doctrine/nickserv/` + Doctrine repository + a migration).
