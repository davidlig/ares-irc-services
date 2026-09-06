# IRC Protocol Architecture

Use when modifying IRC wire handling, network state, protocol modules, or semantic IRC actions.

## 1. Protocol adapter ownership

Concrete protocol behavior belongs under:

```text
Irc/Adapter/Protocol/
├── InspIRCd/
├── UnrealStandalone/
└── UnrealUdb/
```

Inner layers never depend on concrete protocol classes.

## 2. UnrealStandalone and UnrealUdb

They are sibling implementations.

`UnrealStandalone`:
- normal UnrealIRCd integration;
- does not use the UDB module.

`UnrealUdb`:
- UnrealIRCd integration where the UDB module changes protocol/session/storage behavior.

Hard boundary:

```text
UnrealStandalone  ──X──> UnrealUdb
UnrealUdb         ──X──> UnrealStandalone
```

Neither may:
- extend the other;
- compose/delegate to the other;
- import implementation classes from the other;
- share protocol-specific persistence.

Do not create:
- `UnrealFamily`;
- `UnrealBase`;
- `AbstractUnreal*`;
- shared handshake/service-action traits

merely to deduplicate code.

Behavioral duplication is acceptable.

Shared extraction is allowed only for protocol-neutral, stateless primitives whose semantics cannot
reasonably diverge.

## 3. Wire isolation

Keep inside protocol adapters:
- raw lines;
- command tokens;
- handshake grammar;
- mode encodings;
- prefixes;
- UDB frames;
- UDB path codecs;
- protocol checksums;
- protocol-specific timers/state machines.

Translate to stable Irc/Application semantics before crossing inward.

## 4. Protocol selection

A protocol identifier may be used in Bootstrap/configuration to select an adapter.

Business code must not use:

```php
match ($protocolName) { ... }
```

or concrete protocol `instanceof` checks.

Use injected semantic capabilities instead.

## 5. Semantic network actions

Inner boundaries express intent such as:
- introduce service;
- set account identity;
- set vhost;
- force nick;
- set channel/member modes;
- join/part/invite;
- set topic;
- kick;
- add/remove network ban;
- introduce/quit pseudo-client.

The protocol adapter chooses exact wire behavior.

## 6. Capabilities

Do not expand broad interfaces just because one adapter supports an extra feature.

If only one protocol needs something internally, keep it internal.

If Application needs a semantic capability, define a narrow consumer-owned output port without
protocol-specific vocabulary.

## 7. Incoming state

Protocol adapters:
1. parse;
2. validate;
3. update/feed Irc runtime state;
4. publish stable events when other contexts need them.

NickServ/ChanServ/etc. never parse raw IRCd messages.

## 8. Adding a protocol adapter

Before implementation:
- read exact-version documentation;
- document handshake, identifiers, grammar, modes and service operations;
- compare semantic capabilities to existing Irc boundaries.

Then:
- create one adapter namespace;
- implement semantic boundaries;
- add adapter-specific tests;
- register it in Bootstrap.

Adding a protocol must not change unrelated service business logic.

## 9. Tests

Each protocol adapter owns independent tests for:
- parsing/formatting;
- malformed input;
- handshake;
- modes;
- semantic service actions;
- state transitions;
- timeouts;
- reset/disconnect.

`UnrealStandalone` tests do not define `UnrealUdb` implementation and vice versa.

## 10. Review checklist

Verify:
- no concrete IRCd imports in service Domain/Application;
- no wire types escaped the adapter;
- no protocol-name branching outside selection/composition;
- no cross-dependency between Unreal siblings;
- no generic runtime abstraction exists solely for one adapter's state machine.
