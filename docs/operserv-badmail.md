# Enforce the global email-domain policy with OperServ BADMAIL

Operators with `operserv.badmail` can manage a persisted list of email domains
that must not be introduced by ordinary registration or email changes.
Root access follows the existing centralized authorization policy.

## Commands

```irc
/msg OperServ BADMAIL ADD example.org abuse reports
/msg OperServ BADMAIL LIST *
/msg OperServ BADMAIL LIST *.org 2
/msg OperServ BADMAIL DEL 17
/msg OperServ BADMAIL DEL ALL
```

ADD requires a nonblank, single-line administrative reason. Domains are trimmed,
converted to lowercase and checked as ASCII DNS names with at least two labels.
Punycode is accepted; direct Unicode, addresses, URLs, IP addresses, wildcards,
trailing dots and invalid DNS labels are rejected. The domain length is at most
253 characters, with labels of at most 63 characters. Repeated normalized domains
are rejected without changing the original reason.

LIST requires a pattern. Only `*` is a wildcard, matching zero or more characters;
all other characters are literal. Matching ignores case. Numbering uses permanent
entry IDs in ascending order, not row positions. Filtering, pagination and deletion
do not change surviving IDs. DEL ALL deletes entries without resetting their ID
sequence. DEL takes the ID displayed by LIST, and reports a nonexistent ID explicitly.

As with NickServ LIST, the optional page defaults to 1. Integer pages below 1 are
normalized to 1; invalid page text is rejected. A page beyond the available matches
has no rows and still reports the requested page and matching total. An empty list
reports zero pages. Each page contains at most `OPERSERV_BADMAIL_LIST_PAGE_SIZE`
entries (default 50, minimum effective value 1), independently of
`NICKSERV_LIST_PAGE_SIZE`. HELP BADMAIL ADD, DEL and LIST describe each operation.

## Where the policy applies

| Operation | Policy |
|---|---|
| NickServ REGISTER | Reject a supplied blocked domain before account, credential or mail side effects, in EMAIL or TOKEN mode. TOKEN without email remains allowed. |
| NickServ SET EMAIL | Reject the new blocked address before credential validation or account mutation. Other checks remain unchanged. |
| NickServ SASET EMAIL | Explicit administrative bypass; syntax, uniqueness, target protection and authorization still apply. |
| ChanServ SET channel EMAIL | Reject blocked domains unless centralized authorization grants `chanserv.level_founder`. Channel ownership or IRC mode `+o` alone is not that permission. Clearing the email remains allowed. |
| VERIFY, RESEND, RECOVER | No retrospective check: continue using existing stored addresses. |

The match is exact: blocking `example.org` does not block `sub.example.org`.
LIST patterns do not create wildcard blocking rules. Adding a blocked domain does
not rewrite existing account or channel data. The channel email is contact metadata,
not an active notification destination. INFO and HISTORY remain unchanged.

## Persistence and boundaries

The `operserv_badmail` table stores generated IDs, unique normalized domains and
administrative reasons. Apply the new migration using the normal deployment
procedure, after taking the usual backup; do not use schema-update shortcuts.
The migration creates only this table, and its rollback removes only this table.

OperServ owns the policy, list and repository. NickServ and ChanServ each query
a public OperServ input port through their own output port and adapter; neither
imports OperServ entities, repositories or internal use cases. Future services
introducing email addresses can consume the same public policy. This is not a
global SMTP hook: existing-address recovery and operational mail settings stay
unchanged. Each service retains its existing email-format validation. Duplicate
additions are caught at the database boundary without closing the long-running daemon's entity manager.

No BLACKMAIL alias, SAREGISTER command, registration-renewal flow, VREG change or
protocol-specific behavior is introduced.
