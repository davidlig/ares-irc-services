# ChanServ UNBAN

Objective: give channel staff a way to lift a ban without IRCop powers or channel op. The IRCd's
`MODE #channel -b` needs op on the channel, which is exactly what a founder or an access holder
wants to avoid asking for; ChanServ can apply the mode on their behalf.
Branch: `feat/chanserv-unban` (from `main`, independent of the VREG/BLACKMAIL unit).
Delivery strategy: single PR, same shape as the other units.
Test runner: `php vendor/bin/phpunit` (focused, no coverage during development; coverage gate once at
the end via `./scripts/check-coverage.sh 100 --issues`).

## Specs

- S1 — `UNBAN <#channel> [mask|nickname]` removes one ban from the channel: a literal mask when the
  argument contains `!` or `@`, a connected user's mask when it is a nickname
  (`NetworkUserLookup::findByNick()` plus `SenderView::toUserMask()`), and the sender's own mask when
  the argument is omitted.
- S2 — Authorization follows the channel level model: a new `UNBAN` level key joins the existing ones,
  default 100 (the lowest access level, matching the legacy bot's lowest setting), checked with
  `ChanServAccessHelper::requireLevel()`. Founders always pass.
- S3 — The mode is applied through `ChanServNotifierInterface::setChannelModes()` as `-b <mask>` with
  the channel's creation timestamp, never by writing protocol lines from the adapter.
- S4 — The command is a normal ChanServ command (`isOperOnly()` false, permission `IDENTIFIED`), with
  `getExampleKey()` and its string set in the 14 locales: syntax, short, help, example, done, the
  user-not-found reply and the usage reply, reusing the existing `error.invalid_channel`,
  `error.not_identified` and the channel-registration errors instead of duplicating them.
- S5 — `LEVELS <#channel> LIST` shows the new key with its default, and the registered command count
  goes from 71 to 72 (`ServicesCommandHelpAlignmentTest`).
- S6 — Gates: `tests/ChanServ`, `tests/Shared` and `tests/Bootstrap` green, `lint:container`,
  `lint:yaml`, PHPStan max 0 errors, Deptrac 0 warnings / 0 errors, CS-Fixer clean,
  `check-coverage.sh 100` at 100.00%.

## Tasks

- [ ] T1 — Level key: `UNBAN` in `ChannelLevel` (entity constant + `DEFAULTS`) and the enum case with
      its default, plus the LEVELS listing.
- [ ] T2 — The vertical slice: `UnbanUser` use case + handler + result, the command, the notifier call,
      wiring, and the 14-locale string set with the house `/msg %bot% UNBAN #channel mask` example.
- [ ] T3 — Tests: use case (literal mask, nickname, own mask, not found, insufficient level), command
      routing and presentation, LEVELS listing, alignment count 72.
- [ ] T4 — Delivery: commit, push, issue + PR.

## Log

- L1 — User's decision, verbatim: "UNBAN si, KICK no lo veo necesario".
- L2 — 2026-10-19: the reference (`dbots/helps/ch.help`) lists `UNBAN` at access level 1, the lowest
  one, which is why the new level defaults to 100 here (Ares's lowest access is `AUTOVOICE`/
  `VOICEDEVOICE` at 100, `0` being "registered without access").
- L3 — 2026-10-19: implementation notes from the code. `ChanServNotifierInterface` already exposes
  `setChannelModes()` (it is what the bot uses to apply outgoing modes), `NetworkUserLookup` resolves
  nicknames to `SenderView`, and `SenderView::toUserMask()` builds `nick!ident@host`. The closest
  vertical slice to copy is `InviteCommand` plus its `PrepareInvite` use case, including the
  timestamp plumbing. `LEVELS LIST` renders the raw keys, so the new level needs no per-key
  translations.
