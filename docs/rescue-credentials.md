# NickServ rescue credential storage

The rescue credential is a cryptographically generated UUID version 4. Its canonical
representation is lowercase, with surrounding whitespace removed on input. Only valid
UUIDv4 input is accepted. The SQL `registered_nicks.token` column stores its SHA-256
hexadecimal hash (64 lowercase characters), never the original credential. Existing
accounts retain all data and start with a NULL hash. Hash comparisons use constant-time
comparison. The high-entropy random credential is not a human-chosen password.

## Confirmation and replacement

All provisional registration, migration and optional replacement challenges exist only
as hashes in bounded daemon memory before VERIFY. Each account has at most one current
VERIFY challenge; replacement invalidates the previous provisional value, not the confirmed
SQL credential. Recovery-mail challenges use a separate bounded hash-only registry.
Restart loses provisional values, never confirmed SQL credentials.

The SQL token column represents only a confirmed recoveryToken. Pending confirmation
requires an unexpired account and atomically activates it while storing the validated hash.
Active VERIFY confirmation requires the account's authenticated session and a matching
current memory challenge, then conditionally replaces the old hash without activation.
Recovery atomically changes the password together with the expected confirmed hash:
email proof promotes its own hash; existing confirmed proof leaves that hash unchanged
and offers a separate optional memory-only replacement. Successful writes refresh managed
Doctrine entities. Failed writes preserve prior credentials and unconsumed challenges.

## Attempt limits

Rescue operations share a limiter independent of IDENTIFY. Defaults are five failures
per 900-second window and a 600-second cooldown, aggregated separately by account and
stable client identity. Either bucket can block a request. Blocked requests do not
extend their own cooldown. Successful operations deliberately do not clear shared
failure buckets: doing so could let access to one account erase attacks against another
account from the same client, or erase other clients' attacks against one account.
`clearFailures` therefore retains aggregate budgets until natural expiry.

Each bucket retains fewer than five failure timestamps or one cooldown timestamp.
There are at most 10,000 buckets. Exhausting capacity fails closed with a global cooldown
instead of evicting an existing security budget. Maintenance and failure recording prune
expired buckets. All time-dependent decisions receive an explicit current time.

These primitives do not expose hashes through public account DTOs, events, logs or UDB.
Their command integration, private delivery and maintenance wiring belong to the consuming
use cases and inbound adapters.

## Confirmed-credential operations

`RECOVER <nickname>` in TOKEN mode shows instructions without sending mail. EMAIL mode
issues a provisional email token subject to its dedicated TTL and request interval. A
missing valid account email prevents delivery. Requesting or receiving a token does not
revoke the old confirmed credential.

`RECOVER <nickname> <token>` accepts valid proof for an active account. Confirming an
emailed provisional token promotes that same hash; confirming the stored recoveryToken
preserves it and offers an optional new token through authenticated VERIFY. Until VERIFY,
the old credential remains valid; the user can ignore the optional replacement message.
Accounts pending deletion cannot use this recovery flow.

`SET EMAIL <email> <token>` validates the confirmed rescue credential, email format
and uniqueness, then changes the address immediately. It supports accounts without
an existing address. No email is sent and the rescue credential remains valid.
Existing privileged SASET authorization and target protection remain unchanged.

`SET #channel FOUNDER <nickname> <token>` validates the CURRENT founder's confirmed
credential, not the actor's or proposed founder's credential. Target account/status,
successor and channel-count restrictions remain in force. The existing centralized
administrative founder-equivalent bypass remains available without a token.
Transfer still removes redundant target ACCESS and publishes the existing event.
No temporary founder token is generated, consumed or mailed.

All operations share stable client keys in this order: raw protocol IP value,
cloaked host, hostname, then UID. Owner email changes and founder transfers do not
reset shared failure budgets or rotate tokens. Audit metadata excludes credentials,
including extraneous tokens supplied with privileged account-setting commands.
