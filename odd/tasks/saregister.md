# SAREGISTER for NickServ and ChanServ

Objective: administrative registration of nicknames and channels on behalf of third parties.
Branch: `feat/staff-nickname-channel-registration` (fork `juanjiyo/ares-irc-services`, base `main`).
Base commit: `c0bcb09f` (`feat/nickserv-regmode`, PR #57) — REGMODE must land before this PR opens.
Delivery strategy: `single-pr` (one PR, grant is single-use).
Test runner: `php vendor/bin/phpunit`

## Specs

S1. The commands live in their respective services: NickServ for nicknames, ChanServ for channels. Both are restricted to IRC operators with `nickserv.saregister` and `chanserv.saregister`.

S2. The command name is `SAREGISTER`.

S3. `NICKSERV SAREGISTER` syntax depends on the global registration mode:
- `REGMODE TOKEN` (default): `/msg NickServ SAREGISTER <nickname> <password>`
- `REGMODE EMAIL`: `/msg NickServ SAREGISTER <nickname> <password> <email>`

S4. In both modes `SAREGISTER` creates an immediately active account without requiring the usual verification procedure.

S5. In `REGMODE TOKEN` an email address is not required. In `REGMODE EMAIL` an email address is mandatory, and format and uniqueness checks must be enforced.

S6. Both modes reuse the token generation, storage and validation mechanisms defined in #29. Nickname availability and validity checks must still be enforced.

S7. Passwords and tokens must never appear in audit logs or administrative announcements.

S8. Token delivery depends on the mode: in `REGMODE TOKEN` the token is delivered privately to the IRCOp executing the command, displayed only once, not retrievable later and never written to logs. In `REGMODE EMAIL` the token is sent to the email address specified in the command.

S9. `/msg ChanServ SAREGISTER <#channel> <nickname> <description>` registers a channel even if it does not yet exist on the network and without requiring the operator to join it. ChanServ must verify that the nickname belongs to an active registered account and that the channel is not already registered. That account becomes the founder immediately. The operation is recorded in the audit system with the responsible operator, the channel and the designated founder.

## Tasks
- T1 | S1-S8 | inline | complete; commit pending | Risk: items 2,3.
- T2 | S1-S8 | inline | complete; commit pending | Risk: items 2,3.
- T3 | S1-S8 | inline | complete; commit pending | Risk: items 3.
- T4 | S1-S8 | inline | complete; commit pending | Risk: items 3.
- T5 | S1-S9 | inline | gates pending | Risk: items 2,3.

## Log

L1. Maintainer approval, issue #31, comment 6310282409: "I agree with introducing SAREGISTER into Ares IRC Services... The SAREGISTER commands should be implemented in their respective services... Nickname registration must integrate with the REGMODE system defined in issue #29. In TOKEN mode, the rescue token will be delivered to the IRCOp executing the command. ChanServ must allow channels to be registered directly on behalf of third parties, without requiring the operator to be inside the channel or the channel to exist beforehand."

L2. Assumption logged: the per-account channel limit and the registration throttle are NOT applied to `CHANSERV SAREGISTER`, because the approval names only two checks (active account, channel not registered) and the command exists for migrations where limits would block legitimate data. To be confirmed in the PR description.

L3. T1/T2 done. `RegisteredNick::createActive()` added next to `createPending()`; the rescue credential is attached with `replaceRescueTokenHash()` before the single `save()`, so account and credential are written in one insert and no `RescueCredentialPersistence` call is needed. Consequence: the `CredentialUnavailable` outcome does not exist.

L4. Email delivery failure in EMAIL mode returns `RegisteredWithoutMail`: the account and its credential are already persisted, so the operator is told explicitly that the token never left the service instead of losing it silently. Confirmed in the PR description for David.

L5. T3/T4 done. `SAREGISTER` reuses `RegisteredChannel::register()`, the default level set, the akick reset and `ChannelRegisteredEvent`, so UDB/protocol sync and follow-up services behave exactly as with a regular registration. Only the two checks named in the approval are applied, plus channel-name validity enforced by `getChannelNameArg()`.

L6. `saregister` HELP/translations added to 14 locales for both services; command count expectation moved 69 → 71.
