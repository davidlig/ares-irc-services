# Persistence

Use for Doctrine ORM, repositories, XML mappings, transactions, cleanup, and daemon identity-map
behavior.

## 1. Business persistence

Business context implementations live under:

```text
<Context>/Adapter/Out/Persistence/Doctrine/
```

Domain/Application never receive `EntityManagerInterface`.

Doctrine XML mappings live outside Domain code.

## 2. Protocol persistence

Protocol-specific state belongs to the owning protocol adapter.

Example:

```text
Irc/Adapter/Protocol/UnrealUdb/Persistence/
```

If state exists because the UDB module requires it, persistence does not make it a business Domain.

## 3. Repository contracts

If Application needs persistence, define the narrow contract in:

```text
<Context>/Application/Port/Out/
```

Prefer use-case semantics over generic CRUD.

Domain repository interfaces require an actual DDD reason.

Protocol-internal persistence may use internal protocol interfaces/classes when no Application
boundary is crossed.

## 4. Doctrine adapter rules

Doctrine adapters:
- own query builders/entity manager usage;
- map persistence to the correct inner/protocol model;
- return no Doctrine proxy/query objects through ports;
- contain no IRC presentation;
- contain no business authorization.

## 5. Transactions

Application may use a semantic transaction port.

Example:

```text
TransactionBoundary::run(callable $operation)
```

The Doctrine adapter implements it.

Application never calls:
- `flush()`;
- `clear()`;
- transaction methods on EntityManager.

DB atomicity does not make IRC/mail/protocol delivery atomic.
Post-commit effects must be idempotent/retryable/reconcilable when correctness requires it.

## 6. Long-running identity map

Ares is long-running.

EntityManager clear/reset occurs at infrastructure processing boundaries, transaction/lifecycle
components, or persistence batching code.

Do not scatter `flush(); clear();` through use cases.

## 7. XML mapping

When moving/renaming mapped classes update together:
- mapped class;
- repository declaration;
- associations;
- DI aliases;
- tests.

A namespace-only change does not require a schema migration unless schema semantics changed.

## 8. Reference cleanup

**Non-negotiable** — a definitive DROP of a nickname or a channel cleans every dependent
reference it owns in the same transaction, including cross-context dependents. The owning
context publishes its cleanup event inside the deletion transaction and subscriber adapters in
the other contexts perform their local cleanup synchronously. Soft drops (pending deletion)
never clean; RESTORE keeps all dependent data intact.

A hard drop only happens through `ChanDropService::hardDropChannel`,
`NickDropService::hardDropNick` or `CleanupDroppedNickDataHandler` (founder without successor).
Command handlers, `FORBID` flows and purge tasks delegate to those services; only the
dependency-free forbidden placeholders are deleted directly by the `unforbid` paths.

Channel hard drop (`ChannelDropCleanupEvent`, published inside the deletion transaction):

| Reference | Policy | Owner |
|---|---|---|
| `channel_access` | delete | ChanServ |
| `channel_levels` | delete | ChanServ |
| `channel_akick` | delete | ChanServ |
| `channel_history` | delete | ChanServ |
| `memos` / `memo_settings` / `memo_ignores` targeting the channel | delete | MemoServ |
| UDB `C` block projection | delete after commit | UnrealUdb |

Nickname hard drop (`NickDropCleanupEvent`, published inside the deletion transaction):

| Reference | Policy | Owner |
|---|---|---|
| `channel_access.nick_id` | delete | ChanServ |
| `channel_akick.creator_nick_id` | set null | ChanServ |
| `registered_channels.successor_nick_id` | set null | ChanServ |
| `registered_channels.founder_nick_id` | transfer to successor, else drop the channel | ChanServ |
| per-channel data of a founder channel without successor | delete (through the channel drop flow) | ChanServ / MemoServ |
| `memos` (target or sender) / `memo_settings` / `memo_ignores` (target or ignored) | delete | MemoServ |
| `forbidden_vhosts.created_by_nick_id` | set null | NickServ |
| `nick_history.nick_id` | delete | NickServ |
| `oper_ircops.nick_id` | delete | OperServ |
| `oper_ircops.added_by_id` | set null | OperServ |
| `gline.creator_nick_id` | set null | OperServ |
| `motd.creator_nick_id` | delete | OperServ |
| UDB `N` block projection | delete after commit | UnrealUdb |

Immutable historical snapshots are never rewritten on DROP:

- `nick_history.performed_by_nick_id` and `channel_history.performed_by_nick_id` keep the
  operator id; presentation must tolerate the missing account (for example NickServ renders
  `history.unknown_operator`).
- `registered_channels.last_topic_set_by_nick` and `extra_data` JSON payloads are snapshots.

New references — any new table or column storing a nick or channel id ships with:

1. its lifecycle policy (cascade delete, set null, transfer, immutable snapshot);
2. the cleanup path in the owning drop flow (subscriber + handler);
3. unit coverage of the cleanup and an integration check that a hard drop leaves zero orphan
   rows;
4. the policy declared in `MappingReferencePolicyTest` and in the tables above.

External protocol/log/mail effects occur after commit.

## 9. Integration tests

Doctrine adapter tests cover:
- round-trip;
- query semantics;
- deletion/cleanup;
- edge/null mappings;
- transaction behavior when meaningful.

Application tests use repository ports and do not boot Doctrine.
