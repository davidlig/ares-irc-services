# Registration mode and provisional tokens

`REGMODE EMAIL|TOKEN|STATUS` controls the network-wide delivery policy. An absent
policy means TOKEN. Authorized operators can change it without restarting services.
There is no per-account registration mode or `registration_mode` database column.

## Registration and VERIFY

Both modes create a pending account with an expiration time and **NULL confirmed
recovery-token hash**. The provisional UUIDv4 is hashed and kept in bounded daemon
memory, never stored as a recovery credential before validation.

- TOKEN: `REGISTER <password> [email]` privately delivers the token and VERIFY instructions.
- EMAIL: `REGISTER <password> <email>` mails the token. The email explains that after
  validation this same token becomes the permanent recovery credential and must be saved.

`VERIFY <token>` validates the latest unexpired memory challenge, atomically activates
the pending account and stores its hash as the confirmed recoveryToken. The confirmation
period expires; the permanent credential does not inherit that expiration. If registration
is not completed before the pending-account deadline, maintenance removes it and the
nickname becomes available again. Repeating REGISTER never extends that deadline.

Unconfirmed challenges disappear on daemon restart. In TOKEN mode there is no reissue:
wait for pending-account expiration before registering again if the token is lost.

## RESEND

RESEND is disabled while global REGMODE is TOKEN. Under EMAIL, an unexpired pending
account with a valid email can request another token. RESEND generates a new UUID,
replaces the temporary hash and mails it without writing a confirmed credential or
extending the registration deadline. The previous provisional token stops working.
Global policy controls this behavior; no original account registration method is stored.

## Existing accounts and optional replacements

Accounts without a confirmed credential remain active. Successful explicit IDENTIFY
verifies the password before issuing a replacement memory challenge, including when
already identified. Trusted native authentication uses the same issuance boundary after
binding the identified UID to the current account, including burst reconstruction.
Duplicate native identification notifications do not regenerate a challenge.

Active-account VERIFY requires an authenticated session belonging to the account. It
stores the latest memory hash without repeating registration or identification effects.
It can confirm an initial migration credential or an optional replacement issued after
RECOVER. Until optional replacement is confirmed, the old recoveryToken remains valid.
Ignoring the replacement message or allowing it to expire preserves the old credential.

## Recovery

In TOKEN mode, `RECOVER <nickname>` explains that the user must provide their confirmed
recoveryToken. No email is sent.

In EMAIL mode, `RECOVER <nickname> [token]` sends a new provisional recovery token unless
the supplied credential is valid. An absent or invalid account email produces an explicit
cannot-recover response. Requests do not modify the password or confirmed recoveryToken.
`NICKSERV_RECOVER_TOKEN_TTL` defaults to 3600 seconds and applies only to this provisional
token. `NICKSERV_RECOVER_MIN_INTERVAL` defaults to 600 seconds between email requests;
a failed mail attempt also keeps its reserved request interval.

A correct emailed provisional token supplied to RECOVER resets the password and promotes
**that same validated token hash** to the confirmed recoveryToken. The user is informed
of the replacement and must save the token. Older optional challenges are discarded only
after the update succeeds.

A correct confirmed recoveryToken supplied to RECOVER resets the password but preserves
the existing credential. A new optional token is generated in memory and displayed with
`/msg <service> VERIFY <new_token>`. The message explicitly says to ignore it if the user
does not want to change their recovery token. No replacement hash is persisted until VERIFY.

All secret delivery is private and localized. Tokens/hashes never appear on generic
events, audit/staff records or UDB projections. See [rescue credential storage](rescue-credentials.md).

An emailed recovery token already issued in EMAIL mode remains valid until its expiry even if the network switches to TOKEN. TOKEN prevents new recovery emails; it does not revoke existing provisional proof.
