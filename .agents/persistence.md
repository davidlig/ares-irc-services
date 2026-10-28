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

### Native LIST search indexes

Use migrations, not `doctrine:schema:update`, to manage LIST search indexes. Doctrine's portable
schema model does not retain expression indexes, SQLite index collations or PostgreSQL operator
classes. A schema diff is not authoritative for these extensions.

| Engine | Migration-owned extension |
|---|---|
| SQLite | Ordinary `nickname_lower/name_lower COLLATE NOCASE` index |
| PostgreSQL | `LOWER(nickname_lower/name_lower) text_pattern_ops` index |
| MariaDB/MySQL | Indexed virtual `list_search_key`, preserving the source column charset/collation |

The two Doctrine repositories own native LIST predicates and hydrate existing entities through
ORM result-set mappings. Do not add the technical search column to Domain. Preserve database
`LOWER` semantics on PostgreSQL/MariaDB/MySQL: Unicode behavior can differ from PHP normalization.
SQLite uses direct column predicates because its default built-in `LIKE` already folds ASCII,
just like its built-in `LOWER`. Do not override SQLite `LIKE`/`LOWER` or enable `case_sensitive_like`;
the compatibility tests rely on those built-in semantics. Ordinary SQLite indexes also avoid DBAL's
inability to introspect expression indexes. Exact searches retain the
escaped `LIKE` check because equality can treat trailing spaces and linguistic equivalents differently.

Verify these extensions on migrated databases, not only SchemaTool-created fixtures. Future table
rebuilds must preserve/recreate them explicitly. Index creation may lock tables; use the deployment
backup and maintenance procedures. For downgrade, restore compatible code before removing helper
columns; migration rollback removes only the new indexes and generated columns, not source data.

### Global settings changed by commands

Use the common `service_settings` table for all services' global settings that have a command
for runtime changes. The composite key is `(service_name, setting_name)`; `setting_value` remains
`JSON`, preserving the scalar type. The owning service explicitly decodes the value, defines the
semantic type, and validates both the decoded type and allowed values through its typed port.
When encoding scalar values, preserve float identity with `JSON_PRESERVE_ZERO_FRACTION` and
fail on malformed JSON rather than silently coercing or defaulting an existing invalid value.
Never use unchecked casts or infer the type from stored contents alone. Do not create singleton
configuration tables for individual settings or services. Keep typed ports owned by each consumer,
and keep live reads and atomic scoped updates in its persistence adapter.

Every new command of this kind must include a migration seeding each required setting's default;
a fallback or lazy insert alone does not satisfy this requirement. REGMODE seeds are ChanServ
`DIRECT` and NickServ `TOKEN`. Existing `.env` settings stay there while they have no command.
The schema listener declares this DBAL-only table canonically so schema validation still detects
drift rather than silently accepting arbitrary stored columns.

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

## 9. Native migration authoring

Extend Doctrine's `AbstractMigration` directly. Use native, non-deprecated DBAL APIs and keep
schema operations, seeds, data conversions, engine-specific SQL and rollback guards explicit.
Do not add a migration base, trait, schema-target cache, listener bridge or parallel schema model.

- `preUp()` and `preDown()` prepare and validate before queued changes; rejected operations
  must preserve schema, records and migration metadata.
- `up()` and `down()` queue DDL/DML with `addSql()`. Use public platform SQL generators and
  DBAL table/column/index editors where appropriate. Do not construct internal `TableDiff` objects.
- `postUp()` and `postDown()` run after SQL is frozen and also run during a dry run. They cannot
  enqueue more SQL or unconditionally write data. Leave unnecessary hooks inherited.

Native queued SQL does not update Doctrine Migrations' virtual `Schema`. Do not inspect structures
that only preceding queued SQL would create, mutate the supplied schema, or reintroduce target
propagation to simulate them. A fresh chained dry run must plan the same migration SQL, parameters
and types as real execution without creating tables, changing rows or recording applied versions.
Validate results against physical schemas and stored records, not an internal virtual target.
For an installed full-chain SQLite rollback simulation, the UDB conversion may additionally recreate
`uniq_udb_records_block_identity`, which an earlier queued rollback removes. This explicitly accepted
dry-run-only statement must remain executable and non-writing, with the same final physical result.
Do not generalize this exception to other SQL differences or isolated rollback data/index loss.

Use plural `getCreateTablesSQL()` when preserving table/foreign-key ordering. SQLite handles its
foreign keys inline; other platforms can emit them after table creation. Use native CREATE/DROP
INDEX for index-only changes rather than reconstructing SQLite tables from a frozen column list;
later-owned columns, records and unrelated technical indexes must survive index maintenance.
Exercise upgrades with the actual historical schema rather than incomplete hand-written fixtures.

For MySQL/MariaDB LIST indexes, existing source columns determine charset/collation. Only when a
physical source table is absent during fresh planning may the initial table's inherited database
defaults supply them. Never replace an unknown existing column's collation with a guessed default.

Keep version identities and applied-version metadata stable. New schema/data behavior normally
requires a new version; an authorized historical maintenance rewrite must demonstrate equivalent
executed SQL and persisted effects, except explicitly approved SQL-only optimizations that preserve
physical schemas, data and refusal behavior. Respect MySQL/MariaDB non-transactional DDL limitations.

Verify SQLite, MariaDB, MySQL and PostgreSQL separately: platform SQL tests are not real-engine
proof. Use explicitly authorized disposable databases and synthetic data/credentials, retain
engine/version results, and remove only resources created for the verification. Never reuse a
deployment database or its credentials for migration tests.

## 10. Integration tests

Doctrine adapter tests cover:
- round-trip;
- query semantics;
- deletion/cleanup;
- edge/null mappings;
- transaction behavior when meaningful.

Application tests use repository ports and do not boot Doctrine.
