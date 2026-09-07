# ChanServ Policy Invariants

Use for ChanServ ACCESS, AKICK, LEVELS, MLOCK, SECURE, ownership, ranks, and event-driven
enforcement. These behaviors are frozen for the bounded-context migration.

## 1. Identity, founder, and ACCESS

- An unidentified network user always has effective access `-1`, even when their current nickname
  owns the channel or appears in ACCESS.
- An identified founder has implicit access `500`; founders are never stored in ACCESS.
- An identified non-founder has their stored ACCESS value (`1..499`), or `0` when no entry exists.
- ACCESS entries are limited to 100 per channel. A non-founder may only create, update, or remove a
  level strictly below their own effective level. Founder-equivalent IRCop authorization is an
  explicit Application fact and is not inferred by Domain policy.
- ACCESS LIST and ACCESS mutations use the configured `ACCESSLIST` and `ACCESSCHANGE` thresholds.
- A founder transfer target must be a non-suspended registered nickname, must differ from both the
  current founder and successor, and must remain below the configured channels-per-founder limit.
  Founder-level callers transfer directly; any other authorized path requires a throttled, expiring
  token sent to the current founder's email. Completing either path removes an existing ACCESS entry
  for the new founder before publishing the founder-change event.

## 2. LEVELS and ranks

- LEVELS accepts values from `-1` through `499`. Removing/resetting an override restores its
  default; it does not materialize defaults in persistence.
- Defaults are: `AUTOADMIN=400`, `AUTOOP=300`, `AUTOHALFOP=200`, `AUTOVOICE=100`, `SET=499`,
  `ADMINDEADMIN=400`, `OPDEOP=300`, `HALFOPDEHALFOP=200`, `VOICEDEVOICE=100`, `INVITE=200`,
  `ACCESSLIST=400`, `ACCESSCHANGE=499`, `MEMOREAD=200`, `MEMOCHANGE=300`, `AKICK=450`, and
  `NOJOIN=-1`.
- Desired automatic rank is the highest supported rank whose threshold is met, in this order:
  administrator, operator, half-operator, voice. An identified founder receives the highest rank
  supported by the active protocol, including owner when available.
- No automatic rank is granted to an unidentified user, an unregistered nickname, or a registered
  nickname without sufficient access.
- Join handling grants a missing desired rank. SECURE join handling removes only the single rank
  supplied by that join event when it is above the desired rank, and removes that supplied rank when
  there is no desired rank. Other pre-existing ranks are reconciled only by full synchronization.
- Full synchronization removes every supported held rank above the desired rank, then grants the
  desired rank when needed. This downgrade behavior currently applies during full synchronization
  even when SECURE is disabled and must be preserved.
- Rank changes are batched in groups of at most six network operations. Channel activity is touched
  when an identified member with an automatic rank joins or leaves, and when a registered channel
  is synchronized.

## 3. SECURE

- SECURE never grants access; it constrains network ranks to the rank derived from identified
  founder/ACCESS facts.
- An unidentified user is treated as having no desired rank. On join, any supplied rank is removed.
- A live rank grant above the desired rank is immediately removed. List-mode parameters preceding a
  rank change must still be consumed correctly by the IRC adapter.
- Rank synchronization uses the legacy snapshot-at-message-start batch. Enabling SECURE or changing
  founder during one IRC message coalesces by case-insensitive channel name and is enforced at the
  end of the following IRC message, not at the end of the message that raised the change.
- Suspended, forbidden, and pending-deletion channels do not enforce ranks.

## 4. AKICK and NOJOIN

- AKICK entries use case-insensitive wildcard matching against `nick!ident@host`. Expired entries do
  not match; the first non-expired match is enforced with a ban followed by a kick. IRC operators
  are exempt.
- A missing AKICK reason becomes `AKICK: <mask>`. Adding an AKICK protects founder, successor, and
  ACCESS nicknames when the nickname portion of the mask targets them; a wildcard-only nickname
  portion remains allowed for host-based bans.
- AKICK has at most 100 entries per channel. An active duplicate is rejected; an expired duplicate
  is removed and replaced even at the limit. Masks and reasons are limited to 255 characters. A mask
  is safe when its nickname part contains an alphanumeric character, or otherwise when the
  ident-and-host part contains at least four alphanumeric characters. An empty reason is absence.
- When network synchronization is complete, AKICK ADD immediately applies the matching ban/kick to
  current members. The legacy LIST path also reapplies all current AKICK bans and kicks; this unusual
  event-driven side effect is frozen until its command is migrated deliberately.
- `NOJOIN=-1` disables enforcement. Otherwise users whose effective access is below NOJOIN are
  kicked. Unidentified users have level `-1`; IRC operators and ChanServ itself are exempt.
- Join-time NOJOIN runs before rank enforcement. AKICK and NOJOIN also run after network
  synchronization and skip blocked channels.

## 5. MLOCK

- MLOCK ON snapshots the current channel-setting modes and their required parameters, excluding
  service-controlled registered and permanent modes. An absent/empty channel snapshot activates an
  empty lock rather than disabling MLOCK.
- Enforcement computes an exact effective setting over the active protocol's declared capabilities:
  remove supported current setting modes not in the lock, add supported locked modes not currently
  set, preserve mode case, and ignore stale persisted letters that the active protocol cannot express.
- A removal includes the current parameter when the active protocol requires one. An addition
  includes the stored lock parameter when the active protocol requires one.
- The active protocol declares which modes are settings, which require parameters on set/unset, and
  which registered/permanent modes are protected. Concrete letters, sign parsing, parameter order,
  and wire formatting stay in adapters/protocol implementations.
- Initial burst enforcement waits for network synchronization completion. Later channel syncs,
  observed mode changes, and MLOCK updates enforce immediately. Blocked channels and channels with
  inactive MLOCK are ignored.

## 6. Architectural boundary

Framework/IRC events are translated in `ChanServ/Adapter/In/Event` to typed Application requests.
Application loads separate scalar ChanServ-owned rank and MLOCK snapshots, invokes pure Domain
policies, and requests semantic network effects through consumer-owned `Port/Out` contracts. A
rank request must not load MLOCK state, and an MLOCK request must not load ACCESS, LEVELS, members,
or NickServ identities. Outbound adapters translate semantic rank/mode changes to concrete IRC mode
grammar. Symfony subscribers do not query Doctrine, decide authorization, calculate ranks/modes, or
perform wildcard policy themselves.
