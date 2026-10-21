# NickServ registration and rescue confirmation

`REGMODE EMAIL|TOKEN|STATUS` controls new registrations globally. TOKEN is the default
when no policy has been stored. Changing it never rewrites the delivery mode of an
existing pending account. Preexisting pending accounts use EMAIL delivery.

## TOKEN registrations

Use `REGISTER <password> [email]`. A supplied email is validated for format and uniqueness,
but it is not an alternative recovery factor. Registration creates a pending account,
not an active account. NickServ privately shows a UUIDv4 rescue token, its VERIFY command
and a warning to store it safely. Only the hash exists in daemon memory before confirmation;
the SQL token column remains NULL. `VERIFY <token>` atomically activates the unexpired
pending account and stores that same hash as the permanent rescue credential.

Repeated REGISTER does not resend, replace or extend a pending registration's challenge.
There is no TOKEN reissue command. If the token is lost, expires or disappears in a daemon
restart, wait for pending registration expiry and cleanup before registering again.
Capacity exhaustion is reported honestly: the pending account is not activated, and no
successful token delivery is claimed.

## EMAIL registrations and RESEND

EMAIL mode requires `REGISTER <password> <email>`. The pending account stores the UUIDv4
hash and NickServ mails the original credential. VERIFY activates the account without
consuming or replacing its permanent rescue token. Failed mail delivery leaves the
pending account retryable; no silent activation occurs.

RESEND is disabled globally whenever the current policy is TOKEN, including older EMAIL
pendings. A TOKEN pending never uses RESEND even if global policy changes to EMAIL. An
unexpired EMAIL pending under EMAIL policy can RESEND: conditional persistence replaces
its previous credential before mail enqueue without activating the account or extending
its expiry. A concurrent VERIFY prevents stale RESEND from rotating an active credential.
Legacy EMAIL pending accounts lacking a persistent credential must use RESEND first.

## Migrating active accounts

Accounts without a confirmed credential remain active. Every successful explicit IDENTIFY
creates a new temporary token, including password-checked IDENTIFY while already identified.
Incorrect passwords do not rotate the challenge. An already-identified account with a
confirmed token retains the established shortcut behavior.

For native nickname authentication, issuance occurs only after a trusted IRCd identified
state is bound to the current registered nickname and UID. This includes reconstruction
after a burst or service restart. Repeated events for the same tracked identification do
not issue again. In native mode IDENTIFY remains an instructional command, not a password
authentication bypass.

Only the latest account-wide challenge works. VERIFY for an active account requires a
session authenticated as that same account. It stores the hash without reactivating the
account, resetting identification or repeating registration/password-availability events.
Once confirmed, authentication never emits another token. Confirmation and recovery use
the shared rescue attempt limits documented in [rescue credential storage](rescue-credentials.md).

Private token delivery uses the account language and NOTICE/PRIVMSG preference. Tokens
are not published on generic events, and these flows never persist plaintext tokens.
