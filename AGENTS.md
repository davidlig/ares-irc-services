# Ares IRC Services — Agent Contract

This file is the authoritative instruction set for AI coding agents working in this repository.
It is intentionally tool-agnostic so the same rules apply to OpenCode, Codex, Antigravity, and
other coding CLIs.

`AGENTS.md` contains the non-negotiable project rules. Load only the relevant `.agents/*.md` skill
for the current task; do not load every skill by default.

## 1. Project Baseline

- PHP >= 8.5
- Symfony 7.4
- Doctrine ORM 3.6
- PHPUnit 13
- PHPStan 2.x at `--level=max`
- PHP-CS-Fixer with `.php-cs-fixer.dist.php`
- Long-running IRC services daemon
- Doctrine mappings are XML and live outside Domain code
- All user-visible translation keys exist in all 14 supported languages:
  `ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`, `tr`

IRC protocol adapters:

- `InspIRCd`
- `UnrealStandalone` — UnrealIRCd integration without the UDB module
- `UnrealUdb` — independent UnrealIRCd integration for deployments using the UDB module

When behavior depends on a framework/library version, consult current documentation available to
the active CLI instead of relying on model memory.

## 2. Repository Architecture

The codebase is organized **by bounded context**, with a hexagonal structure inside each module.

Top-level architecture:

```text
src/
├── Irc/
│   ├── Domain/
│   ├── Application/
│   │   ├── UseCase/
│   │   ├── Port/
│   │   │   ├── In/
│   │   │   └── Out/
│   │   ├── EventHandler/
│   │   └── PublishedEvent/
│   └── Adapter/
│       ├── In/
│       ├── Out/
│       └── Protocol/
│           ├── InspIRCd/
│           ├── UnrealStandalone/
│           └── UnrealUdb/
├── NickServ/
│   ├── Domain/
│   ├── Application/
│   └── Adapter/
├── ChanServ/
│   ├── Domain/
│   ├── Application/
│   └── Adapter/
├── MemoServ/
│   ├── Domain/
│   ├── Application/
│   └── Adapter/
├── OperServ/
│   ├── Domain/
│   ├── Application/
│   └── Adapter/
├── Shared/
│   ├── Domain/
│   └── Application/
└── Bootstrap/
```

Bounded contexts:

- `Irc`
- `NickServ`
- `ChanServ`
- `MemoServ`
- `OperServ`

`Shared` is a deliberately tiny shared kernel, not a bounded context or dumping ground.
`Bootstrap` is the composition root.

UDB is not a bounded context. It is an UnrealIRCd module/protocol concern owned entirely by
`Irc/Adapter/Protocol/UnrealUdb`.

Read `.agents/architecture.md` before structural changes.

## 3. Dependency Rules

### Domain

Domain is pure PHP business code.

Allowed:
- same bounded-context Domain;
- native PHP types;
- narrowly approved `Shared/Domain` concepts.

Forbidden:
- Symfony;
- Doctrine;
- Amp;
- PSR infrastructure APIs;
- Application or Adapter imports;
- sockets/connections;
- IRC wire messages;
- translators;
- loggers;
- entity managers;
- password hashers;
- mailers;
- random generators;
- infrastructure clocks.

Time, generated values, hashes, and external facts are supplied explicitly when required.

### Application

Application owns use cases and orchestration.

Allowed:
- same bounded-context Domain;
- same bounded-context Application;
- its own `Port/In` and `Port/Out`;
- narrowly approved `Shared/Application` contracts.

Forbidden:
- Symfony/framework classes;
- Doctrine `EntityManager`;
- concrete adapters;
- IRC service Context objects;
- raw IRC lines;
- protocol-specific wire types;
- translation keys as business results.

A use case receives typed input and returns a semantic result.

### Adapter

Adapters translate between external mechanisms and Application boundaries.

- `Adapter/In`: IRC commands, framework event bridges, CLI, external input.
- `Adapter/Out`: Doctrine, mail, security implementations, network actions, logging/audit sinks.
- `Irc/Adapter/Protocol/<Name>`: IRCd-specific wire parsing, formatting, state machines, protocol
  actions, session state, and protocol-specific persistence/projection.

### Bootstrap

`Bootstrap` and Symfony configuration wire implementations together.

Allowed:
- framework/container configuration;
- concrete adapter selection;
- aliases/tags/factories;
- runtime startup wiring.

Forbidden:
- business rules;
- use-case orchestration;
- protocol state machines.

## 4. Port Ownership

Ports are owned by the consumer.

- `Port/In`: operations exposed by a bounded context.
- `Port/Out`: capabilities needed by a use case from outside its inner layers.

Rules:
- never create a global cross-project `Application/Port`;
- never place a port in `Shared` merely because multiple contexts need similar behavior;
- prefer narrow context-specific ports over generic mega-interfaces;
- output ports use semantic names, not technology names.

Good:

```text
RegistrationMailSender
VerificationTokenGenerator
NickNetworkIdentity
TransactionBoundary
```

Avoid:

```text
SymfonyMailerPort
DoctrinePort
GenericServiceActions
UnrealProtocolPort
```

## 5. Cross-Context Communication

A bounded context's Domain and internal Application classes are private implementation details.

Forbidden:
- one context importing another context's Domain entity;
- one context importing another context's internal repository;
- one service use case importing concrete Irc adapters.

Use either:

```text
provider context
    -> stable PublishedEvent
    -> consumer Adapter/In
    -> consumer Application
```

or synchronous boundaries:

```text
consumer Application
    -> consumer-owned Port/Out
    -> Adapter/Out
    -> provider public Port/In
```

Do not bypass boundaries for convenience.

## 6. IRC Service Commands

IRC commands are inbound adapters, not Application handlers.

Flow:

```text
IRC PRIVMSG
  -> <Service>/Adapter/In/Irc/CommandRouter
  -> <Service>/Adapter/In/Irc/Command/<Command>
  -> typed Application input
  -> use case
  -> Domain + Port/Out
  -> semantic result
  -> IRC presenter/translator
  -> network output
```

Application must not know:
- `NickServContext`, `ChanServContext`, `MemoServContext`, `OperServContext`;
- IRC command syntax;
- HELP formatting;
- IRC colors;
- translation keys;
- NOTICE vs PRIVMSG.

Read `.agents/services.md`.

## 7. Protocol Isolation

Concrete protocol behavior belongs only under:

```text
Irc/Adapter/Protocol/
├── InspIRCd/
├── UnrealStandalone/
└── UnrealUdb/
```

`UnrealStandalone` and `UnrealUdb` are sibling implementations.

Non-negotiable:
- neither imports implementation classes from the other;
- neither extends the other;
- neither decorates/composes the other;
- neither shares persistence implementations with the other;
- do not introduce `UnrealFamily`, `UnrealBase`, `AbstractUnreal*`, or shared behavioral traits merely
  to deduplicate them;
- controlled duplication is preferred when it preserves independent evolution;
- shared extraction is allowed only for truly protocol-neutral, stateless primitives whose semantics
  cannot diverge.

Service Domain/Application code never sees:
- concrete IRCd names;
- raw IRC lines;
- UDB frames;
- protocol handlers;
- socket connections.

Read `.agents/protocol.md` and `.agents/unreal-udb.md`.

## 8. Persistence

Doctrine is an outer adapter.

Rules:
- Domain and Application never receive `EntityManagerInterface`;
- business repository contracts normally live in the consuming Application `Port/Out`;
- Domain repository abstractions require a real DDD reason;
- XML mappings remain outside Domain code;
- transaction/identity-map lifecycle belongs to infrastructure;
- protocol-specific persisted state stays inside the owning protocol adapter.

Read `.agents/persistence.md`.

## 9. Security

- never log plaintext passwords, verification tokens, recovery tokens, or secrets;
- never publish plaintext credentials on a generic event bus;
- password hashing/verifying is external to Domain entities;
- random/token generation is behind Application output boundaries;
- authorization, auditing, and IRC presentation are distinct concerns;
- root/IRCop bypass semantics are centralized and explicit;
- peer-provided IRC/UDB data is untrusted until validated.

Read `.agents/security.md`.

## 10. Testing & Quality Gates

Tests must finish with:
- zero failures;
- zero warnings;
- zero notices;
- zero skipped tests;
- zero incomplete tests;
- zero risky tests;
- zero deprecations.

Production code maintains 100% line coverage through the project coverage gate.

Use:
- `createStub()` for return-value-only doubles;
- `createMock()` only when verifying interactions with `expects()`.

During implementation run focused tests without coverage.
Run the full suite with coverage exactly once at final verification:

```bash
./scripts/check-coverage.sh 100 --issues
```

Read `.agents/testing.md`.

## 11. PHP & Code Quality

- `declare(strict_types=1);`
- prefer `final` unless extension is intentional;
- use `readonly` where immutability is appropriate;
- entities expose behavior, not public setters;
- use explicit precise types;
- PHPStan `max` reports zero errors;
- do not add suppression comments to hide design/type problems;
- do not add PHPStan baselines for new code;
- follow `.php-cs-fixer.dist.php`;
- preserve project-enforced Yoda-condition style;
- prefer cohesive classes over `Helper`, `Manager`, `Utils`, or broad `Service` buckets;
- create interfaces only for real boundaries/substitutability/design reasons.

## 12. Workflow

For implementation tasks:

1. inspect the affected bounded context/protocol adapter and direct callers;
2. read only the relevant `.agents` skills;
3. identify invariants and dependency boundaries;
4. implement the complete requested scope;
5. update/add tests together with production code;
6. run focused tests during development;
7. run final quality gates;
8. update docs/agent rules only when their contract actually changes;
9. summarize behavior, architecture, and validation performed.

Parallelize independent discovery aggressively.
Parallelize writes only when files/contracts are independent.

Never parallelize:
- multiple edits to the same file;
- dependent namespace changes;
- port changes alongside consumers before the contract is frozen;
- state-machine extraction with shared mutable state;
- DI rewiring before ownership is settled.

Read `.agents/workflow.md`.

## 13. Final Verification Order

Before claiming a code task complete:

```bash
php -l path/to/modified.php

php bin/console lint:container

php bin/console lint:yaml . --exclude vendor/ --parse-tags

./vendor/bin/phpstan analyse src/ tests/ --level=max --error-format=raw --no-progress

./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

./scripts/check-coverage.sh 100 --issues
```

Run any configured architecture dependency gate (for example Deptrac) at its project-defined step.

Never claim a gate passed unless it was actually run.

## 14. Commits

Commit messages are English Conventional Commits:

```text
type(scope): concise imperative summary

Optional body explaining why and major architectural/behavioral effects.
```

Common types:
- `feat`
- `fix`
- `refactor`
- `test`
- `docs`
- `chore`

Use the narrowest meaningful scope:
- `nickserv`
- `chanserv`
- `memoserv`
- `operserv`
- `irc`
- `protocol`
- `unreal-udb`
- `ci`
- `agents`

Never mention an AI agent in commit messages.
