# Nick registration and recovery

This guide explains NickServ registration, confirmation, rescue-token storage, and account
recovery. Use `/msg NickServ HELP` for current command syntax and visibility rules.

## Quick path

1. The network-wide `REGMODE` is `TOKEN` by default. In TOKEN mode, run
   `REGISTER <password> [email]`; NickServ privately returns a provisional token.
2. In EMAIL mode, run `REGISTER <password> <email>`; NickServ emails the provisional token.
3. Run `VERIFY <token>` before the pending registration expires. A successful verification
   activates the account and confirms that token as its rescue credential.
4. Keep the confirmed token safe. In TOKEN mode, `RECOVER <nickname>` provides instructions;
   in EMAIL mode, NickServ can send provisional recovery proof to the account email.

## Registration policy and lifecycle

`REGMODE {EMAIL|TOKEN|STATUS}` controls the network-wide delivery policy. An absent policy
means TOKEN. Authorized operators can change it without restarting services. The policy is
not stored per account and has no per-account registration-mode database column.

Both registration modes create a pending account with an expiry deadline and a NULL confirmed
rescue-token hash. A provisional UUIDv4 is kept as a hash in bounded daemon memory; the
plaintext token is delivered privately and is not persisted as a recovery credential before
verification.

| Mode | Registration | Confirmation |
|------|--------------|--------------|
| TOKEN | `REGISTER <password> [email]` returns the token and VERIFY instructions privately. | The same token becomes the confirmed rescue credential after successful VERIFY. |
| EMAIL | `REGISTER <password> <email>` mails the token and explains that it must be saved. | The same token becomes the confirmed rescue credential after successful VERIFY. |

The pending-account deadline starts at registration and is not extended by repeating
REGISTER. If it expires before confirmation, maintenance removes the pending account and its
nickname becomes available. Unconfirmed challenges disappear on daemon restart. TOKEN mode
does not reissue a lost registration token; wait for pending-account expiry before registering
again.

### RESEND

RESEND is disabled in TOKEN mode. In EMAIL mode, an unexpired pending account with a valid
email can request another token. RESEND replaces the provisional hash, invalidates the prior
token, and starts a fresh verification period for both the token and pending registration.
It does not persist a confirmed credential. The current global policy controls this behavior;
the account's original registration mode is not stored.
VERIFY and RESEND apply to the sender's current nickname.

`NICKSERV_RESEND_MIN_INTERVAL` defaults to 600 seconds. A failed mail attempt retains its
reserved request interval.

## Existing accounts and optional token replacement

Accounts without a confirmed rescue credential remain active. After a successful explicit
IDENTIFY verifies the password, NickServ may issue a replacement memory challenge, even when
the session is already identified. Trusted native authentication uses the same issuance
boundary after binding the identified UID to the current account, including during burst
reconstruction. Duplicate native-identification notifications do not create duplicate
challenges.

Active-account VERIFY requires an authenticated session belonging to that account and the
latest unexpired challenge. It stores the challenge hash without repeating registration or
identification effects. This can confirm a migration credential or an optional replacement
issued after RECOVER. Until replacement confirmation succeeds, the existing rescue credential
remains valid. Ignoring the replacement message or allowing its challenge to expire preserves
the existing credential.

## Recovery flows

`RECOVER <nickname>` behaves according to the current global REGMODE:

| Mode and proof | Result |
|----------------|--------|
| TOKEN, no proof | NickServ explains that the confirmed rescue credential is required; it sends no email. |
| EMAIL, no proof or invalid proof | NickServ emails provisional proof if the account has a valid email. The password and confirmed credential are unchanged. |
| EMAIL, valid emailed provisional token | The password is reset and that same validated token hash becomes the confirmed credential. The user is told to save the token. |
| Either mode, valid confirmed token | The password is reset while the confirmed credential remains unchanged. An optional replacement challenge is shown privately and can be ignored. |

In EMAIL mode, an absent or invalid account email produces an explicit cannot-recover response.
`NICKSERV_RECOVER_TOKEN_TTL` defaults to 3600 seconds and applies only to provisional
recovery-email proof. `NICKSERV_RECOVER_MIN_INTERVAL` defaults to 600 seconds between email
requests; a failed mail attempt retains its reserved interval. A provisional recovery token
already issued in EMAIL mode remains valid until it expires even if REGMODE changes to TOKEN.
TOKEN prevents new recovery emails; it does not revoke existing provisional proof.
Older optional challenges are discarded only after a recovery update succeeds.

Recovery is not available to accounts pending deletion. A successful password update and its
expected confirmed-token hash change are atomic. Failed writes preserve the previous
credential and leave the challenge unconsumed.

### Registration and rescue attempt limits

`NICKSERV_REGISTER_MIN_INTERVAL` defaults to 600 seconds and is shared by IP across nickname
changes. Once a pending account is persisted, that IP cooldown applies even if challenge
capacity is exhausted or mail delivery fails.

Rescue operations use a limiter independent of IDENTIFY: five failed attempts per 900-second
window and a 600-second cooldown, tracked separately by account and stable client identity.
Either bucket can block a request. Blocked requests do not extend their cooldown. Successful
operations do not clear shared failure buckets, preventing one account or client from erasing
other actors' security budget. `clearFailures` therefore retains aggregate budgets until
natural expiry.

Each bucket stores fewer than five failure timestamps or one cooldown timestamp. Capacity is
capped at 10,000 buckets; exhaustion fails closed with a global cooldown instead of evicting
an existing security budget. Maintenance and failure recording prune expired buckets. All
time-dependent decisions use an explicit current time.

## Credential storage and privacy

Rescue credentials are cryptographically generated, high-entropy UUIDv4 values rather than
human-chosen passwords. Their canonical representation is lowercase; surrounding input
whitespace is trimmed, and only valid UUIDv4 values are accepted. The SQL
`registered_nicks.token` column contains only the SHA-256
hexadecimal hash (64 lowercase characters), never the original token. Hash comparisons use
constant-time comparison. Existing accounts without a confirmed credential retain their
data and have a NULL hash.

Provisional registration, migration, and optional replacement challenges exist only as
hashes in bounded daemon memory before VERIFY. Each account has at most one current VERIFY
challenge. Recovery-email challenges use a separate bounded hash-only registry. Restart
removes provisional values but not confirmed SQL credentials.

Confirmed-token recovery may offer a separate optional memory-only replacement; no replacement
hash is persisted until VERIFY. Recovery-email proof promotes its own hash. Password reset and
the expected credential update occur atomically; successful writes refresh managed Doctrine
entities.

Tokens and hashes are delivered privately and localized. They are not exposed through public
account DTOs, generic events, logs, audit/staff records, or UDB projections. Audit metadata
also excludes extraneous tokens supplied with privileged account-setting commands.

## Other operations that require the confirmed credential

- `SET EMAIL <email> <token>` validates the confirmed credential, email format, and uniqueness,
  then changes the address immediately. It supports accounts without an existing address;
  it sends no email and does not consume the credential. Existing privileged SASET
  authorization and target protection remain unchanged.
- `SET #channel FOUNDER <nickname> <token>` validates the current founder's confirmed
  credential, not the actor's or proposed founder's credential. Target-account/status,
  successor, and channel-count restrictions remain in force. The existing centralized
  administrative founder-equivalent bypass remains available without a token. Transfer
  removes redundant target ACCESS entries and publishes the existing event; it does not
  generate, consume, or email a temporary founder token.

Operations share a stable client identity selected in this order: raw protocol IP, cloaked
host, hostname, then UID. Email changes and founder transfers do not reset shared failure
budgets or rotate tokens.

## Blocked email domains

[OperServ BADMAIL](operserv-badmail.md) rejects newly supplied blocked domains in
REGISTER and ordinary SET EMAIL before workflow side effects. SASET EMAIL explicitly
bypasses it. ChanServ SET EMAIL applies the same policy unless `chanserv.level_founder`
is granted, and still permits clearing the contact. VERIFY, RESEND and RECOVER retain
access to stored addresses: blocking a domain does not retroactively disable recovery.

## ChanServ approval, quotas and cancellation

In APPROVAL mode, REGISTER creates a request, not an approved channel. Pending and
rejected registrations do not appear in NickServ INFO's established channel
associations. Approval makes the founder association visible under the existing
INFO privacy rules.

`CHANSERV_MAX_CHANNELS_PER_NICK` applies to ordinary requesters both at submission
and approval. Rejected registrations never count; the request being accepted does
not count against itself. Other lifecycle states retain their existing counting
policy. Centrally recognized IRCop requesters, including Root, are exempt; an IRCop
reviewer does not exempt an ordinary requester. Acceptance still requires an active,
identified requester with the existing channel-rank eligibility. The requester's
operator exemption uses the authenticated NickServ session, not the reviewer's
identity or an unchecked operator flag. Offline requesters remain ineligible.

An operator with DROP and DROP_FORCE can permanently cancel a pending request using
`DROP <#channel> FORCE`. Plain DROP does not cancel pending requests. Authorized
DROP of a rejected registration releases it permanently, with or without FORCE;
FORCE still requires DROP_FORCE. The reply describes the actual permanent removal,
not a grace period. Ordinary registered-channel DROP and RESTORE retain their
existing grace-period behavior. Cancellation keeps existing DROP audit/cleanup
policies and adds no channel HISTORY action.
