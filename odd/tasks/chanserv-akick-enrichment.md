# ChanServ AKICK enrichment

Objective: complete ChanServ's `AKICK` with the sub-commands the reference service exposes, so channel
staff can change, clear, count, inspect and force their own ban list.
Branch: `feat/chanserv-akick-enrichment` (stacked on `feat/chanserv-unban`).
Delivery strategy: single PR, same shape as the other units.
Test runner: `php vendor/bin/phpunit` (focused, no coverage during development; coverage gate once at
the end via `./scripts/check-coverage.sh 100 --issues`).

## Specs

- S1 — `AKICK` gains five sub-commands on top of the existing `ADD`, `DEL` and `LIST`: `SET`,
  `CLEAR`, `COUNT`, `CHECK` and `ENFORCE`, each with its own syntax, short description, help and
  house-style example in the 14 locales.
- S2 — `SET <#channel> <mask|number> [reason]` changes the reason of an existing entry. The original
  author is preserved: Ares keeps provenance of who added the entry, so the reference's behaviour of
  rewriting the author is deliberately not copied.
- S3 — `CLEAR` removes every entry of the channel and needs its own channel level key,
  `AKICKCHANGE`, default 499, adjustable per channel like every other level, because it is the
  destructive one. The reference splits the same way (`AKICK-CHANGE`).
- S4 — `COUNT` reports how many entries the channel has.
- S5 — `CHECK <#channel> <nick>` lists the entries whose mask matches a connected user, reusing the
  existing `AkickMatchPolicy`; the nickname must be connected.
- S6 — `ENFORCE` applies the current list to the members connected right now, reusing
  `EnforceChannelAkickHandler::handleKnownChannel()` with the channel's current members
  (`ChannelEntryNetworkQuery::findChannel()`), and reports how many were expelled.
- S7 — `VIEW` and `LASTUSE` need data the entity does not have: a use counter and the last time an
  entry was applied. They arrive with a migration adding `uses` and `last_used_at` to
  `channel_akick`, the entity fields, the repository persistence, and the increment in the
  enforcement path. `VIEW` shows mask, reason, author, creation, expiry, uses and last use;
  `LASTUSE` shows the same ordered by last use.
- S8 — The expiry model does not change: Ares keeps an explicit expiry per entry (`expiresAt`) with
  its cleanup, and does not adopt the reference's automatic 7/30/90-day expiry. Documented deviation,
  not an oversight.
- S9 — Gates: `tests/ChanServ`, `tests/Shared` and `tests/Bootstrap` green, `lint:container`,
  `lint:yaml`, PHPStan max 0 errors, Deptrac 0 warnings / 0 errors, CS-Fixer clean,
  `check-coverage.sh 100` at 100.00%.

## Tasks

- [x] T1 — Phase 1, no schema change: `SET`, `CLEAR` with the new `AKICKCHANGE` level, `COUNT`,
      `CHECK` and `ENFORCE`, with their strings in the 14 locales and their tests. DONE in
      `43318381` (29 files, +1828/−53). `CLEAR` requires `AKICK` **and** `AKICKCHANGE` (499), so the
      plain `AKICK` level can never reach it; `ENFORCE` reuses the existing enforcement use case
      against the channel's live members.
- [x] T2 — Phase 2, with migration: `uses` and `last_used_at`, the increment on enforcement, `VIEW`
      and `LASTUSE`, with their strings in the 14 locales and their tests. DONE: migration
      `Version20261020000001` adds the two columns reversibly; the enforcement stamps the entry that
      caught a member through a small `AkickUseRecorder`; `VIEW` shows mask, reason, author, creation,
      expiry, uses and last use, and `LASTUSE` orders by last use with the never-used entries last.
- [x] T3 — Delivery: commits, push, issue + PR. DONE: issue `#45` and PR `#46` on
      `davidlig/ares-irc-services`, stacked on the UNBAN unit (#44). The integration branch
      `integration/verify-all` merges every open unit and needed two order-dependent counters fixed:
      the registered command count (71 → 72) and the migration count (32 → 33). Both are noted in the
      affected PRs.

## Log

- L1 — User's brief, verbatim: "Lo que quieras", after pasting the reference service's `AKICK` help
      (`ADD`, `SET`, `DEL`, `CLEAR`, `LIST`, `VIEW`, `LASTUSE`, `COUNT`, `ENFORCE`, `CHECK`, the
      `AKICK-CHANGE` level for `CLEAR`, and its automatic expiry rules).
- L2 — 2026-10-19: what Ares already has, so the work is not a rewrite. `AKICK` supports `ADD`, `DEL`
      and `LIST`; the entity stores mask, reason, creator, creation and expiry; the repository
      already offers `countByChannel()`, `deleteByChannelId()` and `findByChannelAndMask()`; the
      `EnforceAkick` use case already applies the list to a set of members through
      `AkickActions::banAndKick()`; and `ChannelEntryNetworkQuery::findChannel()` exposes the current
      members with their user masks. So `COUNT`, `CLEAR`, `SET` and `ENFORCE` are mostly wiring, and
      only `VIEW`/`LASTUSE` need new persistence.
- L3 — 2026-10-19: decision on the reference's author rewriting: Ares keeps the original creator and
      only changes the reason, because the creator is the provenance of the decision and rewriting it
      would hide who asked for the ban. Recorded as a deviation in S2.
