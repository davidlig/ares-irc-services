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
├── Bootstrap/
└── Kernel.php
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

Read `.agents/services.md`. For ChanServ policy work also read `.agents/chanserv.md`.
For HELP copy, line wrapping, and IRC presentation colors, also read
`.agents/irc-help-style.md`.

### IRC presentation engine

Commands explicitly choose `Role::Success`, `Role::Error`, `Role::Warning` or
`Role::Information` for each response outcome. Translations contain text and parameters only.
Styles own appearance; `StyleProvider` independently selects a `PresentationStyle`; the shared
adapter `IrcRenderer` interpolates `Fragment` values once and owns formatting resets. Never infer
meaning from translated text, wrap already-colored catalogs, or move concrete colors into commands.
Keep Domain/Application free of this machinery. HELP uses structured components while grouping,
visibility and business policy stay service-owned. See `.agents/services.md` for canonical examples
and `.agents/irc-help-style.md` for grammar and extension points. During staged migration, legacy
paths are explicitly pending rather than examples for new code. No new runtime settings are implied.

### HISTORY policy for commands and options

Before adding a NickServ or ChanServ command, option, or subcommand, ask the user whether
its action should be recorded in the registered nickname's or channel's HISTORY, respectively.
When options or subcommands apply, confirm the decision separately for each one rather than
assuming a single policy for the entire command. Do not assume inclusion or exclusion.

- NickServ example: ask separately whether `SET PASSWORD` and `SET EMAIL` should record
  their actions in the registered nickname's HISTORY.
- ChanServ example: ask separately whether `LEVELS SET`, `LEVELS RESET`, and `LEVELS LIST`
  should record their actions in the registered channel's HISTORY.

These examples illustrate decision granularity; preserve policies already explicitly agreed.

### Staged translation rollouts

When translation work is explicitly staged for review in one locale, modify only the approved
preview locale(s) and wait for the user's explicit validation before translating the remaining
locales. For the current Spanish-first HELP preview, edit Spanish (`es`) only; keep HELP content in the other 13
locale catalogs unchanged until the user validates the Spanish preview. Ordinary reply migration
may proceed independently in all locales. Until then, report
all-locale translation checks and rollout as pending rather than complete.

### Multiline translation catalog values

For Symfony YAML translation catalogs, represent intended line breaks with physical YAML
multiline structure; do not encode line breaks as the escaped `\n` sequence. Use literal block
scalars for plain text whose line breaks must survive parsing; choose the chomping indicator
(`|-` when no trailing newline belongs to the value) to preserve parsed content. Migrated IRC messages contain only localized text and parameters; presentation controls,
colors and decorative markers belong to `Shared/Adapter/In/Irc/Presentation` styles and components.
The existing Bootstrap `!irc` loader remains compatibility support for explicitly unmigrated
values only. Do not globally decode escapes or change literal CTCP data and mail-body newlines.
Prefer double quotes, but allow Prettier-selected quoting only when parsed values and IRC
escape semantics remain unchanged; retain double quotes wherever YAML escape decoding is required.
Use identical English technical section comments at equivalent structural positions across
locales. NickServ and ChanServ HISTORY sections use exactly `# --- HISTORY ---`,
`# --- HISTORY MESSAGES ---`, and `# --- HISTORY EXTRA FIELDS ---` before `history`,
`history.message`, and `history.extra`, respectively.
For multi-line HELP examples, keep the first command as a single-line `example` scalar and
put each remaining command in a single-line entry under a sibling `examples` YAML sequence; the
service formatter renders entries separately, replaces `%command_prefix%` with the uncolored
`/msg <configured bot> ` prefix, and applies exactly two leading spaces to each rendered example.
Do not include outer indentation in translation values. Keep the placeholder immediately before the
command text; supply command emphasis through structured presentation fragments. In MemoServ, the two-space margin
precedes the localized marker, which stays before the generated prefix. Existing locale values with
a literal prefix are not double-prefixed or re-indented during a staged migration. This avoids quoted multiline
examples and `\x20` indentation escapes. Legacy tagged literal blocks preserve display indentation
as real spaces relative to the YAML block indentation. After changing multiline formatting, parse the catalog and
verify decoded line breaks, placeholders, and IRC controls are preserved. This applies to every
key in bot-service catalogs, not only `help.*` values.

Every new or modified service command must declare its argument structure and contextual syntax
through the command validation system. Required and optional parameters must match runtime
behavior. Include routing-level regression tests before considering the command complete.

A new command is complete only when it is registered in the runtime command registry, appears in
`HELP`, has translation keys in all 14 languages, and has a routing/HELP integration test. For an
IRCop command, also register its permission in the service permission catalog and verify role-based
authorization, Root access, and permission-filtered `HELP`. Follow the checklist in
`.agents/services.md`.

### Shared bot-service translations

When adding a bot service or user-visible messages used by multiple services, put generic runtime
messages (such as `unknown_command`, `unknown_help_option`, shared `error.*` messages, and
`duration.*` units/formatting) in
the `common` translation domain (`translations/common.<locale>.yaml`) instead of copying them into
each service catalog. Service contexts must resolve `common` first and then fall back to their own
service domain, so service-specific messages continue to work. Keep workflow-specific and all
`help.*` presentation content in the owning service domain, except generic errors used by every
service such as `unknown_help_option`, which belong in `common`. Preserve placeholder names expected by
all service contexts, and add shared keys for every supported locale unless a user explicitly
authorizes a staged locale rollout. In user-facing command references, leave `/msg <bot>` in the
default foreground and color only the actionable command and its arguments; omit terminal
punctuation when the invocation ends the message.

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
- do not introduce shared behavioral bases, inheritance, composition, or traits merely to deduplicate
  them;
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

Global settings with a command that changes them at runtime use the common `service_settings`
table, scoped by `(service_name, setting_name)`. Never create a per-setting or per-service
singleton configuration table. New commands of this kind must use this storage through
consumer-owned typed ports and include a migration that seeds every required setting's default.
REGMODE defaults are ChanServ `DIRECT` and NickServ `TOKEN`. Settings configured only through
`.env` remain there until they have an associated runtime command; do not migrate them implicitly.

Store `service_settings.setting_value` as JSON. Each owning service explicitly decodes the
value and validates its semantic type and allowed values through its consumer-owned typed port.
Never use unchecked casts or infer the setting type from the stored contents alone.

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

`tests/Shared/Translations/TranslationCatalogTest.php` is English-only and intentionally minimal:
keep basic catalog loading and essential key/placeholder checks, not exhaustive wording snapshots,
cross-locale comparisons, or presentation/style assertions. Do not relocate removed checks into
another test merely to preserve them. Production coverage belongs to focused behavior tests,
including the translation loader tests. This does not narrow the required 14-locale translation review.

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

Before modifying `README.md`, obtain explicit user approval for the specific edit.
General implementation authorization does not imply approval to modify `README.md`.

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
composer yaml-fix
composer yaml-check

./scripts/check-coverage.sh 100 --issues
```

Run any configured architecture dependency gate (for example Deptrac) at its project-defined step.

Install the locked development formatter with `npm ci` (Node 24/npm; not production requirements).
Run `composer yaml-fix` immediately after PHP-CS-Fixer. It formats all `.yaml` and `.yml` files
under `config/`, `translations/` and `.github/workflows/`, plus root `deptrac.yaml`, using only
the locally installed Prettier. Review formatting changes and run the non-mutating `composer yaml-check` before
completion. For bulk normalization, compare before/after Symfony-parsed values, types and custom
tags throughout that scope; preserve IRC escapes, placeholders, meaningful whitespace, mail-body
trailing newlines, Deptrac rules and workflow behavior/scripts/permissions. Formatting does not authorize
translation changes or expansion of the staged Spanish-first HELP rollout.

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
