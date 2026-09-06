# Architecture

Use for class ownership, namespaces, dependency direction, ports, events, and structural reviews.

## 1. Bounded contexts

The repository contains five bounded contexts:

```text
Irc
NickServ
ChanServ
MemoServ
OperServ
```

`Shared` is a tiny shared kernel.
`Bootstrap` is the composition root.

UDB is not a bounded context. It belongs to `Irc/Adapter/Protocol/UnrealUdb`.

## 2. Context structure

Business contexts use:

```text
<Context>/
├── Domain/
│   ├── Model/
│   ├── ValueObject/
│   ├── Policy/
│   ├── Event/
│   └── Exception/
├── Application/
│   ├── UseCase/
│   ├── Port/
│   │   ├── In/
│   │   └── Out/
│   ├── EventHandler/
│   └── PublishedEvent/
└── Adapter/
    ├── In/
    └── Out/
```

Create only directories justified by actual responsibilities.

`Irc` additionally owns:

```text
Irc/Adapter/Protocol/
├── InspIRCd/
├── UnrealStandalone/
└── UnrealUdb/
```

## 3. Placement test

For every class ask:

1. business invariant/state? -> Domain
2. orchestration of an intention? -> Application UseCase
3. capability required externally by a use case? -> Port/Out
4. operation exposed by the context? -> Port/In
5. translation from external input? -> Adapter/In
6. implementation of persistence/mail/security/network/framework access? -> Adapter/Out
7. IRCd-specific wire/session/state/projection behavior? -> Irc protocol adapter
8. pure wiring/configuration? -> Bootstrap

If one class answers several categories, split responsibilities.

## 4. Domain purity

Domain models business meaning only.

No:
- framework services;
- repositories implemented with Doctrine;
- sockets;
- raw IRC/UDB types;
- password hashers;
- random generators;
- mailers;
- translators;
- loggers.

Supply environmental facts explicitly.

Prefer:

```php
$nick->markSeenAt($now);
```

over hidden time acquisition.

Domain events are business facts, not framework events.

## 5. Application design

Use cases:
- receive typed input;
- orchestrate Domain behavior;
- use narrow output ports;
- return semantic results.

Example:

```text
NickServ/Application/UseCase/Register/
├── RegisterNick.php
├── RegisterNickHandler.php
└── RegisterNickResult.php
```

Semantic result examples:
- registered;
- verification required;
- already registered;
- denied;
- throttled.

Do not return translation keys or IRC-formatted messages.

## 6. Ports

Output ports are consumer-owned.

Good names:
- `RegistrationMailSender`
- `PasswordHasher`
- `NickNetworkIdentity`
- `TransactionBoundary`

Avoid technology-shaped contracts:
- `DoctrineRepositoryPort`
- `SymfonyMailerPort`
- `UnrealPort`

Do not use `Shared` as a port registry.

## 7. Cross-context boundaries

Never import another context's Domain model directly.

For asynchronous integration:

```text
provider PublishedEvent
    -> consumer Adapter/In
    -> consumer Application
```

For synchronous integration:

```text
consumer Port/Out
    -> consumer Adapter/Out
    -> provider Port/In
```

This keeps provider internals private.

## 8. Events

Distinguish:

### Domain Event
Business fact inside one context.

### Published Event
Stable event crossing a context boundary.

### Framework Event
Symfony/runtime mechanism handled by an adapter.

A Symfony subscriber should translate/delegate, not become the business policy.

## 9. Protocol adapters

Protocol adapters are technical boundaries, not bounded contexts.

A protocol adapter may own:
- wire models;
- state machines;
- runtime session state;
- timers;
- protocol-specific persistence;
- projection state.

That complexity does not justify moving it to Domain when its semantics come from the IRCd protocol.

## 10. Shared kernel

Move a class to `Shared` only when:
- semantics are identical in every consumer;
- it is context-neutral;
- it is stable.

Duplication of a tiny type may be preferable to semantic coupling.

## 11. Naming

Prefer responsibility names:
- `RegisterNickHandler`
- `ApplyStoredChannelRanks`
- `SendRegistrationMail`
- `ReconciliationRound`

Avoid:
- `Utils`
- `Common`
- `Manager`
- `Helper`
- `Processor`

## 12. Review checklist

Before completion verify:

- Domain has no external/framework imports;
- Application has no concrete adapter/framework imports;
- ports are consumer-owned;
- wire types remain inside protocol adapters;
- framework subscribers contain no hidden business policies;
- command adapters do not own use-case logic;
- `Shared` stayed small;
- cross-context imports use public boundaries;
- protocol state was not promoted into business Domain without business meaning.
