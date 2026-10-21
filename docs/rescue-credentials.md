# NickServ rescue credential storage

The rescue credential is a cryptographically generated UUID version 4. Its canonical
representation is lowercase, with surrounding whitespace removed on input. Only valid
UUIDv4 input is accepted. The SQL `registered_nicks.token` column stores its SHA-256
hexadecimal hash (64 lowercase characters), never the original credential. Existing
accounts retain all data and start with a NULL hash. Hash comparisons use constant-time
comparison. The high-entropy random credential is not a human-chosen password.

## Confirmation and replacement

TOKEN challenges exist only in bounded daemon memory before VERIFY. Each account has
at most one challenge: replacing it invalidates the previous value. Expiration and
conditional removal never delete a newer challenge. Restarting the daemon loses these
unconfirmed challenges, not confirmed SQL credentials. The store defaults to 10,000
accounts and refuses new insertions at capacity; maintenance must prune expired entries.
An existing account's challenge can still be replaced at capacity.

The persistence boundary uses conditional SQL updates to reject stale status or hashes.
Pending confirmation requires an unexpired pending account, activates it and clears its
registration expiry in the same statement. Active migration confirms a credential
without changing account status. Recovery replaces the password and rescue hash together,
only for active accounts whose expected hash still matches. Disabled, forbidden, deleted,
expired or nonexistent accounts cannot be changed by these operations. A transaction
refreshes Doctrine's managed account after successful writes, preventing a later flush
from restoring stale credentials. Callers must not rely on unflushed account edits.

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
