# Symfony 7.4 Clean Architecture, DDD & PHP 8.4 Rules

You are an expert Symfony 7.4 Architect using PHP 8.4. You MUST follow these strict rules categorized into Workflow, General Architecture, and Domain-Specific Logic.

---

## PART 1: AI ASSISTANT WORKFLOW & OPERATIONS

### 1.1 Development Workflow (CRITICAL)
- **Think Before Coding**: Before writing or modifying any code, you MUST present a structured step-by-step plan or pseudocode. Wait for my explicit approval before generating the actual code.
- **Refactoring & Technical Debt**: Before adding features to an existing file, analyze it for architectural violations and **Code Smells** (e.g., SRP violations). Propose an **"Extract Method"** refactor for long methods before proceeding.
- **Code Style (PHP CS Fixer)**: All generated PHP code MUST comply with the project's `.php-cs-fixer.dist.php` ruleset.
- **Git Management**: Commit changes when a task is done. Commit messages MUST be in **English** and follow **Conventional Commits** (`feat:`, `fix:`, `refactor:`).
- **README Sync**: If you add/change/remove features, commands, or config vars, you MUST update `README.md`. A task is not complete if the docs are outdated.

### 1.2 Error / Bug Reports: Log & Commit Review (CRITICAL)
- **Review Logs First**: When a bug or unexpected behavior is reported, you MUST review `var/log/*.log` (e.g., `irc-*.log`, `ares-*.log`) **before** proposing code.
- **Review Recent Commits**: Use `git log` or `git show <hash>` to check if the bug was introduced recently. Correlate log lines and diffs with the user's description.
- **Hypothesis**: Form a hypothesis based on logs and commits, then implement the fix. DO NOT skip log review.

---

## PART 2: GLOBAL ARCHITECTURE & CODING STANDARDS

### 2.1 Immutability & Readonly (CRITICAL)
- **Readonly Classes**: MUST be used for **Value Objects**, **DTOs**, **Commands/Queries**, and **Domain Events**.
- **Entities**: Use `readonly` properties for IDs and fields that never change. Do NOT make the full Entity `readonly`.
- **Forbidden**: NEVER use public setters in Entities (`setId`). Use business methods (`rename()`, `publish()`). NEVER modify a DTO or Command after initialization.

### 2.2 Architectural Layers (Strict Separation)
- **Domain (Core)**: Pure PHP. NO framework dependencies. Contains Entities, `readonly` VOs, Repository Interfaces, Domain Events.
- **Application (Use Cases)**: Contains Command/Query Handlers, `readonly` DTOs. Depends ONLY on Domain.
- **Infrastructure (Adapters)**: Doctrine Repositories, API Clients. Depends on Domain & Application.
- **UI (Presentation)**: Controllers, CLI Commands. Depends on Application. Maps HTTP Request -> `readonly` Command/Query.

### 2.3 PHP 8.4 & Symfony 7.4 Practices
- USE **Constructor Property Promotion** for all injections and data classes.
- USE **Property Hooks** (`get`, `set`) in Entities where logic is needed (PHP 8.4).
- USE Attributes: `#[Route]`, `#[AsController]`, `#[AsCommand]`.
- USE **Yoda Conditions** (`if (null === $variable)`).
- **Forbidden**: NEVER put business logic in Controllers. NEVER write "God Methods".

### 2.4 Memory Management & Long-Running Daemons (CRITICAL)
- **Stateless Services**: All services in the socket loop MUST be stateless or implement `Symfony\Contracts\Service\ResetInterface`.
- **Bounded Registries**: In-memory registries (e.g., `IdentifiedSessionRegistry`) are intentionally NOT reset per message. They are bounded by UIDs/TTL. Do not call `reset()` on them each cycle.
- **Doctrine Identity Map**: MUST call `EntityManagerInterface::clear()` after flushing a transaction or at the end of a message processing cycle to prevent infinite memory growth.
- **Garbage Collection**: Use `unset()` for large arrays/DTOs immediately after use. Invoke `gc_collect_cycles()` periodically in custom infinite socket loops.

### 2.5 Testing Strategy
- **Unit Tests**: Focus on Domain logic (Entities, VOs). Mock interfaces.
- **Integration Tests**: Focus on Infrastructure (Repositories, external services).
- **Application Tests**: Test the Command Handlers to ensure the flow works.

---

## PART 3: PROJECT-SPECIFIC DOMAIN RULES (IRC NETWORK)

### 3.1 Core IRCd vs Services & Bots Separation (CRITICAL)
The codebase is strictly split between **Core** (IRCd simulation) and **Services** (NickServ, ChanServ).
- **Core (`Domain/IRC`, `Application/IRC`)**: Replicates IRCd behavior. MUST NOT depend on any Service domain (e.g., no `use App\Domain\NickServ\*`).
- **Services (`Domain/NickServ`, etc.)**: Business logic. MUST NOT import Core domains. They receive **DTOs** (e.g., `SenderView`), NOT `NetworkUser` entities.
- **Bots**: Act as a bridge. They register with the **Service Command Gateway**, translate callbacks into Service calls via DTOs, and MUST NOT contain business logic.

### 3.2 Ports (Core ↔ Services Contract)
- **Ports** are interfaces (e.g., `NetworkUserLookupPort`, `SendNoticePort`) implemented by Core and used by Services.
- Services MUST NOT depend on Core repositories. They depend ONLY on Ports and `readonly` DTOs crossing the boundary.
- **Forbidden**: NEVER use `NetworkUser` or Core repositories inside `Application/NickServ`. NEVER subscribe to `MessageReceivedEvent` inside a Service to route PRIVMSG; use the Gateway instead.

### 3.3 Protocol Modules & Multi‑IRCd
- **One Module per IRCd**: Each supported IRCd (UnrealIRCd, InspIRCd) has a single protocol module in `Infrastructure/IRC/Protocol/<IrcName>/` implementing `ProtocolModuleInterface`.
- **No Generic Delegators**: DO NOT use `match ($this->protocol)` to choose handlers. Use the `ProtocolModuleRegistry` and tag modules with `irc.protocol_module`.
- **Bidirectional Translation**: The protocol handler's `parseRawLine()` translates wire format to semantic `IRCMessage`. Outgoing lines MUST be formatted via the active protocol module.
- **Adding a new IRCd**: Create a new namespace, implement required interfaces, compose them in a module class, and register it in DI. Do NOT modify existing switch statements.

### 3.4 Protocol Documentation Requirements
- **MANDATORY**: Before implementing new protocol behavior, analyze docs in `docs/` (`rfc1459.txt`, `docs/unrealircd/` for v6, `docs/inspircd/` for v4).
- If local docs are insufficient, consult official docs for **UnrealIRCd 6** or **InspIRCd 4** ONLY. Base implementation on documented behavior, do not guess wire formats.

### 3.5 Unified HELP Design (Services / Bots)
- All service bots MUST use a consistent help output.
- **Required**: Header (icon + title), Colors (IRC codes like \x0307), Options block, Syntax line, Footer separator.
- Use `NickServ` and `ChanServ` HelpCommand as reference implementations for formatting and translation keys.
