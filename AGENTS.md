# Ares IRC Services — Clean Architecture, DDD & PHP 8.5

You are an expert Symfony 7.4 Architect using PHP 8.5. All rules here are NON-NEGOTIABLE.

---

## 1. Golden Rules

### 1.1 Parallelize EVERYTHING by Default

Launch multiple independent operations in a SINGLE message:
- Reading multiple files → Multiple `read` tool calls
- Searching for patterns → Multiple `grep`/`glob`
- Exploring different areas → Multiple task agents
- Writing independent files → Multiple `write` tool calls

**Do NOT parallelize:** sequential dependencies, same-file modifications, debugging with mental context, bug investigation (log correlation).

### 1.2 Documentation Lookup with Context7 MCP (CRITICAL)

When you need documentation for Symfony 7.4, PHP 8.5, Doctrine ORM 3.6, PHPUnit 13, or any library in `composer.json`, use Context7 MCP if available:

1. `context7_resolve-library-id` — find the library ID
2. `context7_query-docs` — ask the specific question
3. If unsatisfied → retry with `researchMode: true`

**NEVER rely solely on training data** — verify with Context7 when available. Full reference: `.agents/documentation.md`.

---

## 2. Pre-Commit Verification Order (NON-NEGOTIABLE)

```bash
# 1. PHP syntax check (on modified files)
php -l path/to/file.php

# 2–6. Single command:
php bin/console lint:container && \
php bin/console lint:yaml . --exclude vendor/ --parse-tags && \
./vendor/bin/phpstan analyse src/ tests/ --level=max --error-format=raw --no-progress && \
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php && \
./scripts/check-coverage.sh 100 --issues
```

**CRITICAL — two verification phases (NON-NEGOTIABLE):**

- **While writing tests:** after finishing new or modified test files, run only those files with `./vendor/bin/phpunit --no-coverage --display-all-issues tests/.../Test1.php tests/.../Test2.php`. Repeat focused runs as needed while developing; do not run the full suite.
- **Only after the whole implementation is complete:** run `./scripts/check-coverage.sh 100 --issues` once. The script runs the full PHPUnit suite WITH coverage and enforces the gate.
- NEVER run a standalone full PHPUnit suite immediately before or after `check-coverage.sh`; that executes the suite twice. NEVER run `check-coverage.sh` after each test file or intermediate feature.

**PHPStan Analysis (NON-NEGOTIABLE):**
- `./vendor/bin/phpstan analyse src/ tests/ --level=max --error-format=raw --no-progress` MUST pass with ZERO errors.
- Fixing every single issue reported by PHPStan (type errors, missing iterable value types, dead code, invalid parameters, return types) is MANDATORY before proceeding to code formatting and test coverage.

If any step fails, fix it and re-run from the failed step — never skip ahead.

**Commit order:** implement → fix phpstan errors → php-cs-fixer → commit. Never commit unformatted code or code with PHPStan errors.

---

## 3. Test Coverage (NON-NEGOTIABLE)

**100% coverage on ALL new code. No exceptions.**

- Every new class MUST have tests with `#[CoversClass(ClassName::class)]`
- Every public method MUST have at least one test
- Every branch/condition MUST be tested
- Run focused PHPUnit commands for new/modified test files while developing; run `./scripts/check-coverage.sh 100 --issues` only once after the complete implementation, before claiming completion
- Use `createStub()` for unverified dependencies, `createMock()` ONLY with `expects()`
- PHPUnit configuration MUST fail warnings, skipped, incomplete, risky, and deprecated tests, and require coverage metadata (`#[CoversClass]` or equivalent).

---

## 4. Immutability & Readonly

- **Value Objects**, **DTOs**, **Commands**, **Domain Events**: MUST be `readonly class`
- In a `readonly class`, **NEVER** repeat `readonly` on properties or constructor promotions (redundant in PHP 8.2+; enforced by `no_redundant_readonly_property`).
- **Entities**: Use `readonly` properties for IDs and immutable fields. Do NOT make the full class `readonly` if state changes.
- **NEVER** use public setters (`setId`, `setName`). Use business methods (`rename()`, `suspend()`).
- **NEVER** modify a DTO or Command after creation.

---

## 5. Architectural Layers (Strict Separation)

| Layer | Location | Depends On | Allowed Imports |
|-------|----------|------------|-----------------|
| **Domain** | `src/Domain/` | Nothing (pure PHP) | None |
| **Application** | `src/Application/` | Domain only | Domain |
| **Infrastructure** | `src/Infrastructure/` | Domain + Application | Symfony, Doctrine |
| **UI** | `src/UI/` | Application | Symfony console |

- **NEVER** put business logic in Controllers or Bots
- **NEVER** import `Domain\IRC` entities in Application layer — use `Port/` DTOs and interfaces
- **NEVER** use `match`/`switch` over protocol names — use `ProtocolModuleRegistry`
- PHP 8.5 features: constructor promotion, property hooks, typed constants (`const string X = 'v';`)
- Use Yoda conditions: `if (null === $variable)`

### 5.1 Protocol Agnosticism of Shared Code (NON-NEGOTIABLE)

Shared code (Domain, Application ports, the shared runtime, and the cross-protocol handlers) is the services base: it must stay agnostic to every IRCd implementation. **NEVER modify shared code to add a capability only one IRCd needs.**

- **Prohibited shared surfaces** (never modified for one protocol's needs):
  `src/Domain/IRC/Protocol/*`, `src/Infrastructure/IRC/Runtime/*` (`IRCClient`, factories),
  `src/Infrastructure/IRC/Protocol/AbstractProtocolHandler.php`, `src/Infrastructure/IRC/Protocol/UnrealFamily/*` (frozen traits),
  shared Application ports (`ProtocolModuleInterface`, `ProtocolServiceActionsInterface`, `ProtocolHandlerInterface`, …).
- **Protocol-specific capabilities** are exposed through **NEW optional interfaces** (e.g. `src/Application/Port/OperclassServiceActionsInterface.php`) implemented ONLY by the protocol modules that support them. Consumers feature-detect with `instanceof` — never by adding methods to shared ports.
- **Protocol-specific behavior** (session ticks, deadlines, wire workarounds) lives **inside the protocol's own namespace** (`src/Infrastructure/IRC/Protocol/<Name>/`), driven from its own handler — never wired into the shared read loop or abstract base.
- A change is a **design violation** if it forces edits outside the protocol's own namespace and its own tests. Redesign with a new port, tag, or domain event instead.
- Tests of shared components MUST NOT reference protocol-specific capabilities (no `tick`/`operclass` expectations in `IRCClientTest`, `AbstractProtocolHandler` tests, etc.).

### 5.2 SOLID & Hexagonal Review Rules (MANDATORY before declaring work done)

Full checklist: `.agents/architecture/README.md` → "SOLID & Hexagonal Review Rules".

- **SRP**: one responsibility per class; separate state machines get separate collaborators (extracted, e.g. `UdbOclgView` inside its adapter namespace).
- **OCP/ISP**: extend with NEW optional ports + `instanceof` feature detection; never grow shared interfaces or force no-op implementations.
- **DIP**: Application imports `Application/Port/` + Domain ONLY — never `Infrastructure\*`. Infrastructure may implement ports and use external libraries but never leaks concrete classes inward.
- **Hexagonal**: protocol adapters own their wire types (`UdbFrame`, raw lines); adapter internals (locks, views) never cross the Port boundary. Domain events = inbound direction; ports = outbound direction.
- **Self-check before declaring done**: (1) shared contract touched? (2) one class, two responsibilities? (3) Application → Infrastructure import? (4) new classes without `#[CoversClass]` tests / full coverage? Any "yes" is a design violation — refactor or justify explicitly.

---

## 6. Skill Reference Table

For detailed guidance, consult the corresponding skill file:

| Area | Skill File | Use When |
|------|-----------|----------|
| **Workflow** | `.agents/workflow.md` | Parallel execution, pre-commit chain, bug investigation |
| **Documentation** | `.agents/documentation.md` | Context7 MCP, library versions |
| **Architecture** | `.agents/architecture/README.md` | Bounded contexts, layers, Port boundary |
| **Entities** | `.agents/architecture/entities.md` | Entity design, property hooks, VO patterns |
| **Events** | `.agents/architecture/events.md` | Domain events, subscribers |
| **Drop Cleanup** | `.agents/architecture/drop-cleanup.md` | Ref cleanup on NickDrop/ChannelDrop |
| **Database** | `.agents/database/README.md` | Doctrine ORM, XML mapping, migrations, EM clear |
| **Services** | `.agents/services/README.md` | Core vs Services, Ports, Bots |
| **Commands** | `.agents/services/commands.md` | Command handler structure and interface |
| **Permissions** | `.agents/services/commands-permissions.md` | Authorization, voters, IRCop permissions |
| **Translations** | `.agents/services/commands-translations.md` | i18n YAML, IRC color codes, 14-language rule |
| **Testing** | `.agents/services/commands-testing.md` | Test patterns for command handlers |
| **Live MCP Testing** | `.agents/services/live-mcp-testing.md` | IRC/MariaDB MCP validation against a running IRCd |
| **Bots** | `.agents/services/bots.md` | New bot/service implementation checklist |
| **IRCop** | `.agents/services/ircop-commands.md` | IRCop permission system |
| **Debug** | `.agents/services/debug-actions.md` | Debug logging for IRCop commands |
| **HELP** | `.agents/services/help-design.md` | Unified HELP output format |
| **Testing** | `.agents/testing/README.md` | Core testing rules |
| **Test Patterns** | `.agents/testing/testing-patterns.md` | Common test patterns by layer/type |
| **Coverage** | `.agents/testing/testing-coverage-priorities.md` | Test priorities map |
| **Memory** | `.agents/memory/README.md` | Daemon memory management, Doctrine clear, GC |
| **Protocol** | `.agents/protocol/README.md` | IRCd modules, wire format |
| **New IRCd** | `.agents/protocol/adding-new-ircd.md` | Adding new IRCd support checklist |

---

## 7. Translations Rule (CRITICAL)

Every translatable string MUST exist in ALL 14 languages: `ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`, `tr`. Files at `translations/<service>.<lang>.yaml`. A task is incomplete if any key is missing in any language.

**Syntax formatting rule:** Required positional arguments use `<>`, optional arguments use `[]`, and **choice/selection arguments MUST use `{}`**.

**IMPORTANT — General vs subcommand syntax:** The general syntax shows ALL subcommands and their arguments in one line. Since some subcommands (LIST, CLEAR) don't require the argument, the general syntax uses `[]` for optionality. Each subcommand's own `syntax` should use the correct bracket for its specific context.

```
General: ROLE PERMS <rol> {LIST|ADD|DEL|CLEAR} [permiso|ALL]  ← optativo (LIST/CLEAR no lo usan)
Add:     ROLE PERMS <rol> ADD {permiso|ALL}                    ← elección requerida
Del:     ROLE PERMS <rol> DEL <permiso>                        ← requerido
List:    ROLE PERMS <rol> LIST                                 ← sin argumento
Clear:   ROLE PERMS <rol> CLEAR                                ← sin argumento
```

---

## 8. Data Integrity — Ref Cleanup on Drop

Any feature storing `nickId` or `channelId` references MUST define cleanup behavior:
- Subscribe to `NickDropEvent` or `ChannelDropEvent`
- Choose CASCADE DELETE / SET NULL / TRANSFER strategy
- Implement cleanup in repository + subscriber
- Full checklist: `.agents/architecture/drop-cleanup.md`

---

## 9. Live MCP Validation Safety

When IRC or MariaDB MCP servers are available, use them for live smoke/integration validation after implementing IRC service behavior. This is mandatory for new or changed service commands when it can be done safely.

- PHPUnit, linting, and coverage remain mandatory; MCP checks never replace them.
- Use `.agents/services/live-mcp-testing.md` before any live IRC or DB validation.
- Never run destructive commands against real nicks or real channels.
- Always create temporary resources for live tests, such as `NickTest<suffix>` or `#test-<suffix>`.
- Use `OPENCODE_IRC_ROOT_NICK` only when root, IRCop, or founder privileges are required.

---

## 10. Implementation Playbooks (Best Practices)

### 10.1 Playbook: Implementing a New Feature / Command

Follow this deterministic step-by-step workflow:

1. **Domain Layer (`src/Domain/{Service}/`)**:
   - Create/modify Entities, Value Objects (`readonly class`), and Domain Events (`readonly class`).
   - Define business methods on entities (never public setters).
   - Define Repository interfaces (pure PHP, zero infrastructure imports).
   - If referencing `nickId` or `channelId`, define Drop Cleanup behavior (subscribe to `NickDropEvent` / `ChannelDropEvent`).
2. **Application Layer (`src/Application/{Service}/`)**:
   - Create Command Handler implementing `{Service}CommandInterface`.
   - Implement `getName()`, `getAliases()`, `getMinArgs()`, `getRequiredPermission()`, `execute()`.
   - Access only Domain and `src/Application/Port/` (use `SenderView`, `SendNoticePort`, not Core entities).
3. **Translations (ALL 14 Languages)**:
   - Add keys to `translations/{service}.{lang}.yaml` (`ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`, `tr`).
   - Follow syntax bracket standard: `<>` required positional, `[]` optional, `{}` choices.
4. **Infrastructure Layer (`src/Infrastructure/{Service}/`)**:
   - Doctrine XML mapping in `config/doctrine/` (if persistent state).
   - Repository implementation implementing Domain repository interface.
   - Event Subscribers for domain events.
5. **Dependency Injection (`config/services.yaml`)**:
   - Register command handler tagged with `{service}.command`.
   - Register repository, subscriber, and any service parameters.
6. **Tests & Verification (100% Coverage)**:
   - PHPUnit tests with `#[CoversClass(ClassName::class)]` for all layers.
   - Use `createStub()` for unverified stubs, `createMock()` ONLY when asserting `expects()`.
   - After writing tests, run only the new/modified test files with `./vendor/bin/phpunit --no-coverage --display-all-issues Test1.php Test2.php ...`.
   - Once the complete implementation is finished, run the Pre-Commit chain: `lint:container`, `lint:yaml`, `phpstan` (zero errors), `php-cs-fixer`, `./scripts/check-coverage.sh 100 --issues` (the only full-suite run).
   - Live MCP validation against temporary resources (when MCP is available).

### 10.2 Playbook: Implementing a New IRCd Protocol

Follow this modular structure in `src/Infrastructure/IRC/Protocol/<Name>/`:

1. **Research & Docs**: Document wire tokens, handshake, modes, and commands in `docs/<name>/`.
2. **Protocol Module**: Implement `ProtocolRuntimeModuleInterface` (the runtime-only extension that adds `getHandler()`):
   - Shared module contract: `getProtocolName()`, `getServiceActions()`, `getIntroductionFormatter()`, `getChannelModeSupport()`, `getUserModeSupport()`, `getNickReservation()`.
   - Runtime contract: `getHandler()`. Network state adapters are registered separately and are not exposed by the module.
3. **Protocol Handler**: Implement every `ProtocolHandlerInterface` method: handshake, incoming-message handling, parse/format, protocol name, and supported capabilities.
4. **Network State Adapter**: Create it under `src/Infrastructure/IRC/Network/Adapter/`; implement `getSupportedProtocol()` and `handleMessage()` from `NetworkStateAdapterInterface`.
5. **Protocol Service Actions**: Implement the complete current `ProtocolServiceActionsInterface`, including invitations, G-lines, and temporary pseudo-client lifecycle in addition to user/channel/service actions.
6. **Protocol Supports/Formatters**: Implement `ServiceIntroductionFormatterInterface`, `ChannelModeSupportInterface`, `UserModeSupportInterface`, and a nullable/real `ServiceNickReservationInterface` implementation as required by the module contract.
7. **DI Registration (`config/services.yaml`)**:
   - Tag `<Name>Module` with `irc.protocol_module`.
   - Add the separately registered `<name>` adapter to `ProtocolNetworkStateRouter::$adapters`.
8. **100% Test Coverage**: Complete unit test suite for all protocol components.

### 10.3 Playbook: Implementing a New Service / Bot

1. **Domain Context (`src/Domain/{Service}/`)**:
   - Entities, Value Objects, Domain Events, Repository Interfaces.
2. **Application Context (`src/Application/{Service}/`)**:
   - Dispatcher (`{Service}Service`), Context (`{Service}Context`), Command Interface & Registry (`!tagged_iterator {service}.command`), Notifier Interface (`{Service}NotifierInterface`).
3. **Infrastructure Context (`src/Infrastructure/{Service}/`)**:
   - Bot implementing `ServiceCommandListenerInterface`, `{Service}NotifierInterface`, and `EventSubscriberInterface`.
   - On `NetworkBurstCompleteEvent`: call `$module->getServiceActions()->introduceService(...)`.
   - On `onCommand(string $senderUid, string $text)`: resolve `SenderView` via `NetworkUserLookupPort` and delegate to `{Service}Service::dispatch()`. Zero business logic in Bot!
   - Send notices via `SendNoticePort::sendNotice()`.
4. **Translations & HELP**:
   - 14 languages YAML with unified HELP format (`.agents/services/help-design.md`).
5. **Configuration (`config/services.yaml` & `.env`)**:
   - Service UID parameter, service nick, ident, realname.
   - Tag Bot with `app.service_command_listener` and `kernel.event_subscriber`.
   - Add service UID to `CtcpHandler::$serviceUidMap`.
6. **Tests (100% Coverage)**:
   - Full coverage for Domain, Application, and Bot (burst with/without module, onCommand, notifier).
