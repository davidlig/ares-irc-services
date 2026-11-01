# Automatic nickname modes

Operators with `nickserv.saset` can save ordinary-user mode preferences for a
registered nickname. Preferences belong to that nickname, not to an OperServ
role. `SET MODES` is intentionally unavailable.

## Quick path

```irc
/msg NickServ SASET Pedro MODES B
/msg NickServ SASET Pedro MODES
/msg NickServ HELP SASET MODES
```

The first command replaces the complete preference; the second clears it.
Supply case-sensitive letters **without** `+`, `-`, spaces or mode parameters.
Repeated letters normalize to one intention. Unsupported letters and unknown
network capabilities reject assignment without changing preferences or HISTORY.
Clearing remains possible when capabilities are unknown.

Root identities, service nicknames, registered IRC operators and connected IRC
operators cannot be targets. Other SASET options retain their existing policy.
The ordinary SASET permission, Root bypass and permission-filtered HELP apply.

## Available intentions

The catalogs below show default letters. Actual support requires the corresponding
modules throughout the known linked network. InspIRCd remapped letters are resolved
from advertised semantic names and parameter classifications, not guessed.

| Protocol | Ordinary automatic mode catalog |
| --- | --- |
| InspIRCd | `i w B c d D g I L N R S T` |
| UnrealStandalone | `i w B T G p R Z D d` |
| UnrealUdb | `w B T G p R Z D d` |

Letters are protocol-specific: for example, InspIRCd `S` strips formatting while
UnrealIRCd `S` is privileged services-bot status and is excluded. UnrealIRCd `B`
is ordinary bot status. Operator, services, authentication, TLS-status and host
management modes do not become ordinary preferences through this command.
Native UDB `i` is excluded because its profile-mode implementation does not
maintain the invisible-user counter. Configuration-dependent modes such as
UnrealIRCd `I` are excluded.

SQL stores typed semantic intentions rather than raw protocol letters. Switching
protocols does not reinterpret an old letter: compatible intentions map to the new
protocol, while incompatible intentions remain stored but dormant. A successful
replacement replaces the entire preference, including dormant intentions.

Unreal adapters independently track direct-peer `PROTOCTL USERMODES`, remote
`SINFO`, server introductions, updates and splits. Unknown inventory on any known
server blocks new assignments; a known empty safe intersection is distinct from
unknown support. Reconnection clears previous capability discovery.

## Application and ownership

Changes are saved first. Eligible authenticated sessions receive defaults
immediately and when explicit or implicit identification/recovery establishes
identity. Repeating an already successful IDENTIFY does not introduce another
application trigger. These are **defaults, not a mode lock**: manual mode changes
do not cause automatic reapplication.

For non-native delivery, only modes successfully added by this feature are owned.
Pre-existing modes are preserved. Replacement, clearing and identity loss revoke
only owned modes. A manual removal relinquishes ownership even if another source
later adds that letter. Quit, UID reuse and connection loss discard ownership;
restart and burst data never reconstruct it from currently active bits. The
existing nickname identity-preservation policy decides whether a nickname change
actually loses identity.

Replies confirm **saved preferences**, not acknowledged live IRCd application.
Network delivery is post-save and may fail; it does not roll back the preference.
A write failure is logged without aborting later saved-event subscribers. Successful
forced changes also update the local network view because an IRCd need not echo
services-originated changes.

## Native UDB and persistence

`registered_nicks.automatic_modes` is nullable JSON. Existing nicknames have no
preference after the additive migration. Clearing stores no intentions.

Native UDB receives only compatible semantic preferences as `N::<nick>::modes`
with a wire `+` prefix; clearing removes that record. Incremental updates and full
exports use the same encoder. When the active native inventory is known, excluded
intentions remain dormant and are omitted from that projection. Unknown, offline
or non-native active inventories preserve the static native-compatible projection
rather than erasing preferences. Capability validation changes immediately, while
native records reconcile during existing full/incremental projection cycles—not
on every inventory packet.

The module remains authoritative for authentication and effect ownership. Native
profile effects require its own authenticated nickname proof, credential/access
checks and unsuspended profile; an externally assigned account or `+r` is not that
proof. Ares never substitutes forced-mode application for native profile effects.

Effective SASET assignments/replacements and CLEAR operations record actor and semantic
before/after values in nickname HISTORY. No-op requests add no HISTORY entry.
Administrative command audit remains separate. No plaintext credentials are
included in mode preferences or their events.

## Verification boundaries

Focused tests cover parsing, protection, semantic persistence, HISTORY, mode
ownership, delivery failures, capability filtering and native full/incremental
agreement. Doctrine tests exercise SQLite round trips and additive migration;
platform-generated SQL checks are not deployment-engine execution proof.
External IRCd sources and configuration are not modified by this feature.
