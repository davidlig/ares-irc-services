# NickServ registration mode

`/msg NickServ REGMODE EMAIL|TOKEN|STATUS` manages the network-wide registration
policy. It requires an identified IRC operator with the `nickserv.regmode`
permission; centralized Root authorization continues to apply.

- `STATUS` reads the current policy without changing it.
- `EMAIL` selects email delivery for new registration challenges.
- `TOKEN` selects private IRC delivery and explicit `VERIFY <token>` confirmation.
- A missing persisted policy means `TOKEN`.

The policy is stored in the singleton `nickserv_registration_policy` table and
read directly for each operation, so changes take effect without a daemon restart.
Setting an already-selected mode is a successful no-op with no change audit or
staff announcement. Actual changes use the existing command audit/staff pipeline;
only the operation and new mode are included, never registration credentials.

Changing policy does not rewrite existing accounts or their pending delivery
mode. While the global policy is TOKEN, RESEND is disabled even for older EMAIL
registrations; TOKEN registrations never support RESEND. Existing accounts and
pending-registration workflows are integrated separately from policy storage.

## Persistence and rollback

Migration `Version20261021000002` creates only the policy table, without changing
account data or pre-populating policy. Its rollback drops only that table. The
mapping is external XML; NickServ Domain and Application remain framework-free.

Concurrent updates use conditional writes and a bounded retry. A contended command
fails rather than claiming an update it did not perform. Queries deliberately do
not reuse Doctrine identity-map state in this long-running process.
