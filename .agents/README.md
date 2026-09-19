# Agent Skills

`AGENTS.md` is the authoritative project contract.

Load only the skill relevant to the current task.

| Skill | Read when |
|---|---|
| `architecture.md` | Ownership, modules, ports, events, dependency direction |
| `services.md` | NickServ/ChanServ/MemoServ/OperServ commands, bots, HELP, translations, auth |
| `chanserv.md` | ChanServ ACCESS, AKICK, LEVELS, MLOCK, SECURE, founder and rank invariants |
| `protocol.md` | IRC protocol adapters, network actions, adding/changing an IRCd |
| `unreal-udb.md` | UnrealIRCd UDB module wire/session/reconciliation/takeover work |
| `persistence.md` | Doctrine, repositories, XML mapping, transactions, cleanup |
| `security.md` | Passwords, tokens, authorization, auditing, sensitive logging |
| `testing.md` | PHPUnit, coverage, architecture tests, live validation |
| `daemon.md` | Long-running state, memory, timers, event-loop behavior |
| `workflow.md` | Investigation, implementation cadence, verification, commits |

## Skill maintenance

- one rule has one primary home;
- link to the owning skill instead of copying large rule blocks;
- keep examples architectural and timeless;
- do not store test counts, coverage snapshots, dated inventories, or bug history;
- do not encode CLI-specific instructions unless the repository requires them;
- when a global architectural rule changes, update `AGENTS.md` first.
