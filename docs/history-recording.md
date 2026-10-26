# NickServ HISTORY recording

HISTORY is persistent account history, not raw command logging or IRCop
security auditing. Only successful, explicitly included operations create new
entries. Successful repetitions are included; validation failures, denied
operations and rejected empty-clear outcomes are not. Existing command replies,
permissions and separate IRCop audits are unchanged.

## Recorded operations

NickServ records initial account activation (`VERIFY`) and successful verification
email delivery (`RESEND`) with the source IP. Credential confirmation that does
not activate an account is not an activation. `NOEXPIRE ON/OFF` records the sender
nickname. `USERIP` records the sender in the queried registered account's history;
it does not record returned IP/host data or create accounts for unregistered
queries. Existing recovery, password/email change and suspension history retain
their existing metadata and are not duplicated.

## History management

`HISTORY ADD` remains a single manual note. `HISTORY DEL` and `HISTORY VIEW` do
not create command-audit entries. `HISTORY CLEAR` atomically deletes prior entries
and inserts one replacement entry identifying the sender, even when the history
was empty. If replacement insertion fails, deletion is rolled back. The response
counts only deleted entries, not the replacement.

Other commands, read-only list queries and all HELP variants remain excluded.
No new permissions, schema migrations or historical backfill are introduced.
New history messages are available in all 14 supported locales; HELP copy is
outside this change.

## Data boundaries

New entries use explicit metadata through each bounded context's history service.
Legacy event-driven callers retain their original IP/host enrichment. Passwords,
hashes, verification/recovery/transfer tokens and secret channel keys must never
be stored in HISTORY. Application use cases own recording; IRC adapters supply
only required typed actor/time facts and present the semantic results.
