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

Every stored nick/channel reference defines:
- cascade;
- set null;
- transfer;
- immutable historical snapshot.

Local atomic cleanup belongs in the same transaction.
External protocol/log/mail effects occur after commit.

## 9. Integration tests

Doctrine adapter tests cover:
- round-trip;
- query semantics;
- deletion/cleanup;
- edge/null mappings;
- transaction behavior when meaningful.

Application tests use repository ports and do not boot Doctrine.
