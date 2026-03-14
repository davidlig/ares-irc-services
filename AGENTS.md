# Symfony 7.4 Clean Architecture, DDD & PHP 8.4 Rules

You are an expert Symfony 7.4 Architect using PHP 8.4. You MUST follow these strict rules categorized into Workflow, General Architecture, and Domain-Specific Logic.

---

## PART 1: AI ASSISTANT WORKFLOW & OPERATIONS

### 1.1 Development Workflow (CRITICAL)
- **Think Before Coding**: Before writing or modifying any code, you MUST present a structured step-by-step plan or pseudocode. Wait for my explicit approval before generating the actual code.
- **Refactoring & Technical Debt**: Before adding features to an existing file, analyze it for architectural violations and **Code Smells** (especially "Long Methods" or classes violating SRP). If you detect a method with too much logic, you MUST propose a refactor using the **"Extract Method"** technique to break it down into smaller, highly descriptive, and single-purpose private methods before proceeding with the new feature.
- **Code Style (PHP CS Fixer)**: All generated PHP code MUST comply with the project's `.php-cs-fixer.dist.php` ruleset. Automatically format the code or ensure it aligns with strict Symfony standards and PHP 8.4 features before presenting it.
- **Commit order (CRITICAL)**: You MUST run PHP CS Fixer **before** committing. The correct sequence is: (1) implement or modify code, (2) run `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php` (or the project's configured command), (3) then commit. Never commit first and fix style in a separate commit; each commit must already contain formatted code so that style fixes and functional changes stay in the same commit.
- **Git Management**: Commit changes when a task is done. Commit messages MUST be in **English** and follow **Conventional Commits** (`feat:`, `fix:`, `refactor:`).
- **README Sync**: If you add/change/remove features, commands, or config vars, you MUST update `README.md`. A task is not complete if the docs are outdated.

### 1.2 Error / Bug Reports: Log & Commit Review (CRITICAL)
- When the user reports an **error**, **bug** or **unexpected behaviour** (e.g. "there is an error with…", "check the logs", "fix this issue"), you MUST **review the application logs** **before** proposing or writing code.
- **Where**: Read the relevant log files under `var/log/*.log` (e.g. `irc-*.log`, `ares-*.log`, `maintenance-*.log`). Prefer the most recent or the file the user has open or mentions.
- **Why**: The logs show the real flow of IRCd ↔ services (messages received/sent, events, subscriber actions). This context is essential to understand the order of events, race conditions, and the actual failure point instead of guessing.
- **Recent commits**: You MUST also **review recent commits** (`git log` or the commit history) to check whether the bug was **introduced in a specific commit**. If the user indicates a commit (e.g. "el bug se introdujo en el commit X"), inspect that commit with `git show <hash>` and base the fix or re-implementation on it. Even when no commit is mentioned, scanning the last few commits touching the affected area can pinpoint regressions.
- **Workflow**: (1) Read the pertinent `var/log/*.log` entries for the reported scenario. (2) If a commit is mentioned or the bug looks like a regression, review the relevant commit(s) with `git show` / `git log`. (3) Correlate log lines and diff with the user's description. (4) Form a hypothesis and then search the codebase or implement the fix. Do **not** skip log review when debugging reported errors.

---

## PART 2: GLOBAL ARCHITECTURE & CODING STANDARDS

### 2.1 Immutability & Readonly (CRITICAL)
- **Value Objects**: MUST be `readonly class`. They represent immutable concepts (e.g., `Email`, `Money`).
- **DTOs**: MUST be `readonly class`. Input/Output data should not change after creation.
- **Commands & Queries**: MUST be `readonly class`. They represent a request at a specific point in time.
- **Domain Events**: MUST be `readonly class`. History cannot be changed.
- **Entities**: Use `readonly` properties for IDs and fields that never change. Do NOT make the full Entity class `readonly` if it requires state changes via business methods.
- **Forbidden**: NEVER use public setters in Entities (`setId`, `setName`). Use business methods (`rename()`, `publish()`). NEVER modify a DTO or Command after initialization.

### 2.2 Architectural Layers (Strict Separation)
- **Domain (Core)**: Pure PHP. NO framework dependencies (attributes allowed only if they don't couple logic). Contains Entities, `readonly` VOs, Repository Interfaces, Domain Events.
- **Application (Use Cases)**: Contains Command/Query Handlers, `readonly` DTOs. Depends ONLY on Domain.
- **Infrastructure (Adapters)**: Doctrine Repositories, API Clients, Mailers. Depends on Domain & Application.
- **UI (Presentation)**: Controllers, CLI Commands. Depends on Application. Maps HTTP Request -> `readonly` Command/Query.

### 2.3 PHP 8.4 & Symfony 7.4 Practices
- USE **Constructor Property Promotion** for all injections and data classes.
- USE **Property Hooks** (`get`, `set`) in Entities where logic is needed (PHP 8.4).
- USE Attributes: `#[Route]`, `#[AsController]`, `#[AsCommand]`.
- USE Dependency Injection via `private readonly` properties in services/handlers.
- USE **Yoda Conditions** (`if (null === $variable)`) to prevent accidental assignments.
- **Forbidden**: NEVER put business logic in Controllers. NEVER write "God Methods" or "Long Methods". A method MUST NOT contain too much logic, mix levels of abstraction, or handle multiple responsibilities. You MUST strictly adhere to the **Single Responsibility Principle (SRP)**. If a method does more than one thing, it is an unacceptable **Code Smell**.

### 2.3.1 Constructor Promotion Exceptions
The following files use explicit property declaration + assignment in constructor (not promotion) due to technical limitations:

- **Tagged Service Iterables**: `*CommandRegistry.php` classes (e.g., `NickServCommandRegistry`, `ChanServCommandRegistry`) receive `iterable $commands` from Symfony DI and transform it to an associative array before storing.
- **Post-processing in Constructor**: `MaintenanceScheduler.php` receives `iterable $tasks`, transforms via `iterator_to_array()` and `usort()`, then stores the result.
- **Array-building from Multiple Dependencies**: `SetCommand.php` handlers receive individual handler dependencies and build an associative array (`$this->handlers = ['FOUNDER' => $setFounderHandler, ...]`).

These are acceptable exceptions because:
1. Symfony tagged services pass iterables, not arrays
2. The code performs transformations before assignment
3. Converting to promotion would either be impossible (iterables) or reduce readability

### 2.4 Memory Management & Long-Running Daemons (CRITICAL)
Since the application involves long-running workers and infinite socket loops (e.g., IRC services, bot connections), you MUST proactively prevent memory leaks:

- **Stateless Services**: All services injected into the loop or message handlers MUST be stateless. If temporary state is absolutely required, the service MUST implement Symfony's `Symfony\Contracts\Service\ResetInterface` and explicitly clear its state after each cycle.
- **Registries with bounded state**: In-memory registries (e.g. `IdentifiedSessionRegistry`, `ChannelRankSyncPendingRegistry`, `PendingVerificationRegistry`, `RegisterThrottleRegistry`) are intentionally **not** reset per message: their state is bounded by connected UIDs (QUIT/ServerDelinked cleanup), channel count, TTL, or maintenance pruners. Do not call `reset()` on them each cycle or session/pending data would be lost. Only implement `ResetInterface` for services that accumulate per-message state with no other cleanup.
- **Doctrine Identity Map (CRITICAL)**: The Doctrine `EntityManager` caches every entity it touches. You MUST explicitly call `EntityManagerInterface::clear()` after flushing a transaction or at the end of a message processing cycle. Failing to do so will cause the memory to grow infinitely.
- **Variable Cleanup**: USE `unset()` for large payload arrays, raw string buffers from the socket, or DTOs immediately after they have been processed and are no longer needed.
- **Garbage Collection**: In custom infinite socket loops, explicitly invoke `gc_collect_cycles()` periodically or at the end of each major tick to force PHP to clean up circular references.
- **Logging & Profiling**: When logging inside the loop, avoid keeping references to large objects in the log context.

### 2.5 Testing Strategy
- **Unit Tests**: Focus on Domain logic (Entities, VOs). Mock interfaces.
- **Integration Tests**: Focus on Infrastructure (Repositories, external services).
- **Application Tests**: Test the Command Handlers to ensure the flow works.
- **Context-specific skills**: For testing tasks (adding tests, reviewing coverage, prioritising what to test), consult **`.agents/testing/`**: see `.agents/README.md` (index of types) and **`.agents/testing/README.md`** (conventions, commands, **CRITICAL RULES** for deprecations and coverage analysis). Full detail in `.agents/testing/testing-coverage-priorities.md`.

---

## PART 3: PROJECT-SPECIFIC DOMAIN RULES (IRC NETWORK)

### 3.1 Core IRCd vs Services & Bots (CRITICAL)

The codebase is split into **Core** (IRCd simulation) and **Services** (NickServ, ChanServ, MemoServ, etc.). They MUST remain fully decoupled.

#### 3.1.1 Core (IRCd)
- **Scope**: `Domain/IRC`, `Application/IRC`, `Infrastructure/IRC`. Replicates IRCd behaviour as a linked server: servers (link/unlink), users (join/quit, modes, channels), channels (modes, users, uchannel modes). Abstracted by IRCd type (UnrealIRCd, InspIRCd) via Protocol Handlers and Network State Adapters.
- **Rules**: Core MUST NOT depend on or reference any Service domain (e.g. NickServ). No `use App\Domain\NickServ\*` or `use App\Application\NickServ\*` in Core. Core MAY define extension-point interfaces (e.g. `LocalUserModeSyncInterface`) that Infrastructure implements. Core exposes behaviour via **Ports** (interfaces) that Infrastructure implements; Core does not "know" who consumes these ports.

#### 3.1.2 Ports (Core ↔ Services contract)
- **Ports** are interfaces (e.g. in `Application/Port`) that the Core implements and Services use. Services MUST NOT depend on Core entities or repositories; they depend ONLY on Ports and DTOs.
- **DTOs** crossing the boundary (e.g. `SenderView` for "user who sent a command") MUST be `readonly` and contain only the data Services need (uid, nick, ident, hostname, cloakedHost, ipBase64, etc.). No `NetworkUser`, `Channel`, or other Domain/IRC types in Service code.
- **Required ports** (implemented by Core, used by Services):
  - **NetworkUserLookupPort**: e.g. `findByUid(string $uid): ?SenderView`. Resolve a connected user for command context; return `null` if not on network.
  - **SendNoticePort**: e.g. `sendNotice(string $targetUid, string $message): void`. Send a NOTICE to a UID. Core implements via connection/protocol.

#### 3.1.3 Services (NickServ, ChanServ, MemoServ, etc.)
- **Scope**: `Domain/NickServ`, `Application/NickServ`, `Infrastructure/NickServ` (and other service modules). Business logic for each service (registration, identify, protection, memos, etc.).
- **Rules**: Application layer of a Service MUST NOT import `Domain\IRC` or `Application\IRC`. No `NetworkUser`, `NetworkUserRepositoryInterface`, `MessageReceivedEvent`, or other Core types. Use only Ports and DTOs (e.g. `SenderView`, `NetworkUserLookupPort`, `SendNoticePort`). Command handlers and services receive **DTOs** and optional UID/nick strings, not `NetworkUser`. Context objects (e.g. `NickServContext`) MUST use these DTOs for "sender", not Domain/IRC entities. Infrastructure of a Service (bots, subscribers) MAY listen to Core domain events only when necessary (e.g. `NetworkBurstCompleteEvent` for introducing the bot). When they need "user in network" data, they MUST use `NetworkUserLookupPort` and work with DTOs, not Core repositories.

#### 3.1.4 Bots (modular)
- **Bots** (e.g. NickServBot, MemoServBot) are the bridge between the network and a Service. They MUST be modular and MUST NOT couple business logic to the Core.
- **Rules**: Bots register with the **Service Command Gateway** (single entry that listens for PRIVMSG targeting a service name). The Gateway invokes a callback with `(senderUid: string, text: string)`. The bot then uses `NetworkUserLookupPort` to get a `SenderView` if needed and calls the Service (e.g. `NickServService::dispatch(text, senderView)`). Bots MUST NOT contain business logic; they only: (1) register with the Gateway, (2) translate Gateway callback into a Service call with DTOs, (3) optionally react to Core lifecycle events (e.g. burst complete) to introduce the service user. Replying to users MUST go through the Service (which uses `SendNoticePort`), not by the bot writing directly to Core connection without going through the port. When a new Service is added, create a new Bot module that registers with the same Gateway and delegates to that Service; do not put Service-specific logic in the Core or in a shared "generic" bot that knows about all services.

#### 3.1.5 Forbidden in Services / Bots
- NEVER use `NetworkUser`, `NetworkUserRepositoryInterface`, `ChannelRepositoryInterface`, or any Core repository/entity inside `Application/NickServ` (or any Service application layer) or inside Service command handlers.
- NEVER subscribe to `MessageReceivedEvent` inside a Service to route PRIVMSG to the Service; use the Service Command Gateway and register the bot's callback instead.
- NEVER pass `NetworkUser` (or other Domain/IRC types) into `NickServContext`, `NickServService::dispatch()`, or any Service application-layer method; use `SenderView` or an equivalent DTO from the Ports layer.

### 3.2 Unified HELP design (Services / Bots)
- **All** service bots (NickServ, ChanServ, MemoServ, and any future service) MUST use a **unified HELP design** so that help output is consistent across the network.
- **Required elements**: (1) **Header**: a coloured section header with icon (e.g. ℹ) and title, aligned with a trailing line of dashes (e.g. `\x02\x0307 ℹ Title \x0F\x0314────…\x03`). (2) **Colours**: use IRC colour codes consistently (e.g. \x0307 for command names, \x0314 for secondary lines, \x0304 for errors). (3) **Options**: when a command has sub-options, show an "Options:" block with each option name and short description. (4) **Syntax**: always show a "Syntax:" line with the command syntax (e.g. `\x0307Syntax:\x03 \x02<command> <param>\x02`). (5) **Footer**: end with a coloured separator line (e.g. `\x0314─────────────────────────────\x03`).
- **Syntax convention in translations**: required params `<param>`, optional `[param]`, fixed alternatives `{OPTION1|OPTION2}`. Help text for commands with alternatives (e.g. ACCESS ADD/DEL/LIST) MUST explain what each option does when the user runs HELP <command>.
- **Reference implementation**: NickServ and ChanServ HelpCommand (sendHeader, showGeneralHelp, showCommandHelp, showSubCommandHelp) and their translation keys (help.header_title, general_header, command_line, options_header, syntax_label, footer).

### 3.3 Protocol Modules & Multi‑IRCd (CRITICAL)

Support for multiple IRCd types (UnrealIRCd, InspIRCd, future P10/ircu, etc.) is done via **one module per IRCd type**. There must be no generic "protocol" class that holds a switch/match over IRCd names.

#### 3.3.1 Protocol module per IRCd
- **One module per IRCd**: Each supported IRCd has a single **protocol module** (e.g. `UnrealIRCdModule`, `InspIRCdModule`) that implements `ProtocolModuleInterface` and bundles: Protocol handler (handshake, parseRawLine, formatMessage, handleIncoming), Service actions (setUserAccount, setUserMode, forceNick, killUser), Service introduction formatter (formatIntroduction for the pseudo-client), Vhost command builder (getSetVhostLine, getClearVhostLine).
- **Location**: Each module lives in `Infrastructure/IRC/Protocol/<IrcName>/` (e.g. `Unreal/`, `InspIRCd/`, `P10/`). All protocol-specific code for that IRCd stays in that namespace. `ProtocolModuleInterface` (in `Application/Port`) defines the bundle; the registry and the active-connection holder work with this interface only. No reference to concrete IRCd names in shared protocol code.

#### 3.3.2 No generic delegators in IRC\Protocol
- **Forbidden**: A class in `Infrastructure/IRC/Protocol/` (or similar) that does `match ($this->protocol) { 'unreal' => ..., 'inspircd' => ..., default => ... }` to choose handler, formatter or actions. That would couple all IRCd types in one place.
- **Correct**: A **registry** (`ProtocolModuleRegistry`) that receives tagged modules (`irc.protocol_module`) and exposes `get(string $protocolName): ProtocolModuleInterface`. The registry does not hardcode protocol names; it builds a map from each module's `getProtocolName()`. Adding a new IRCd = add one module + tag; no change to the registry class. Bots and other code that need protocol-specific behaviour obtain the **active module** from `ActiveConnectionHolder::getProtocolModule()` and then call `getHandler()`, `getServiceActions()`, `getIntroductionFormatter()`, `getVhostCommandBuilder()` as needed. They must not receive a "delegator" that switches on protocol.

#### 3.3.3 Bidirectional wire translation
- **Incoming (wire → app)**: The protocol handler's `parseRawLine(string $rawLine): IRCMessage` translates the wire format (e.g. P10 tokens, RFC 1459) into the canonical in-memory form (`IRCMessage` with semantic command names: PRIVMSG, NICK, NOTICE, etc.). Domain, Application and Services never depend on wire format.
- **Outgoing (app → wire)**: All lines sent to the IRCd (NOTICE, introduction, MODE, KILL, etc.) must be produced via the **active protocol**: build an `IRCMessage` (or equivalent intent) and use the handler's `formatMessage(IRCMessage): string`, or use the module's formatters/actions that output wire lines. No hardcoded `sprintf` with a single IRCd's format in shared or bot code. The protocol handler is the only place that knows the wire format for that IRCd; both parsing and formatting go through it (or through helpers exposed by the same module).

#### 3.3.4 Adding a new IRCd (e.g. P10/ircu)
- Create a new namespace `Infrastructure/IRC/Protocol/<Name>/` (e.g. `P10/`).
- Implement: `ProtocolHandler` (parseRawLine, formatMessage, handshake, burst/EB–EA), `NetworkStateAdapter` (wire messages → domain events), `ServiceIntroductionFormatter`, `ProtocolServiceActions`, and `VhostCommandBuilder` if the IRCd supports it. Implement the **module** class implementing `ProtocolModuleInterface` that composes the above and returns them from the interface methods. Register the module in DI with the tag `irc.protocol_module`. Register the network state adapter in the protocol router config (e.g. `adapters: { ..., p10: '@...P10NetworkStateAdapter' }`). **Do not** add a new branch to any `match`/`switch` over protocol name in a shared class; the new module is discovered via the registry and routing config.

#### 3.3.5 Protocol documentation (MANDATORY before implementation)
- **Before** implementing any **new or changed** behaviour in the protocol layer (handshake, wire format, modes, service actions, introduction, vhost, etc.), you **MUST** analyse the relevant documentation. **Reference versions**: use **UnrealIRCd 6** and **InspIRCd 4** only; do not rely on docs for other versions.
- **Where (local, preferred)**: Documentation in the repo under `docs/` is divided into base RFCs and **IRCd-specific** docs: **RFCs (Base Protocol)**: For core IRC concepts, standard commands, and numeric replies, always analyze the RFCs in `docs/rfc/` (specifically `rfc1459.txt`, `rfc2812.txt`, `rfc7194.txt`). **UnrealIRCd (v6)**: when `IRC_PROTOCOL=unreal` (or when touching Unreal code), read and use `docs/unrealircd/`. **InspIRCd (v4)**: when `IRC_PROTOCOL=inspircd` (or when touching InspIRCd code), read and use `docs/inspircd/`.
- **Where (official online, if local is insufficient)**: UnrealIRCd 6: [https://www.unrealircd.org/docs/](https://www.unrealircd.org/docs/). InspIRCd 4: [https://docs.inspircd.org/](https://docs.inspircd.org/). Use **that same version** (Unreal 6, InspIRCd 4) only.
- **Workflow**: (1) Determine which IRCd(s) are affected. (2) Consult `docs/rfc/` for standard IRC behavior and numerics. (3) Read the pertinent files under `docs/unrealircd/` and/or `docs/inspircd/`. (4) If needed, use the official URLs above for details not in the repo. (5) Base the implementation on the documented behaviour and format; do not guess wire formats or mode letters. (6) If documentation is still missing or unclear, note it in the plan or code comments before implementing.
- This applies to: protocol handlers, network state adapters, service introduction formatters, service actions, vhost commands, and any code in `Infrastructure/IRC/Protocol/`.
