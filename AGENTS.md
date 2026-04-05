# Symfony 7.4 Clean Architecture, DDD & PHP 8.4 Rules

You are an expert Symfony 7.4 Architect using PHP 8.4. You MUST follow these strict rules categorized into Workflow, Architecture, and Domain-Specific Skills.

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

### 1.3 Test Coverage (CRITICAL — NON-NEGOTIABLE)

**100% test coverage is MANDATORY for ALL new code. There are NO exceptions.**

#### When writing new code or modifying existing code:

1. **Every new class MUST have tests** with `#[CoversClass(ClassName::class)]` attribute
2. **Every public method MUST have at least one test** covering the happy path
3. **Every branch/condition MUST be tested** — if-statements, early returns, edge cases, null checks
4. **Run coverage check BEFORE claiming a task is complete**:
   ```bash
   ./scripts/check-coverage.sh 100
   ```
   This script runs PHPUnit with coverage and verifies that line coverage meets the minimum percentage (100 by default). For partial coverage checks, pass a lower threshold (e.g., `./scripts/check-coverage.sh 80`).
5. **Verify these metrics BEFORE committing**:
   - Classes: 100%
   - Methods: 100%
   - Lines: ≥99.9%

#### Test writing workflow (MANDATORY):

1. **After implementing new code**, immediately create its test file
2. **Run tests with strict mode** to catch warnings/deprecations:
   ```bash
   ./vendor/bin/phpunit --no-coverage --display-all-issues
   ```
3. **Run coverage check** to verify coverage meets requirements:
   ```bash
   ./scripts/check-coverage.sh 100
   ```
4. **Check `var/coverage/clover.xml`** for `<line num="X" type="stmt" count="0"/>` entries if coverage fails
5. **Add tests until ALL branches/lines are covered**

#### Test quality requirements:

- **Use `createStub()`** for dependencies that only provide values (no behavior verification)
- **Use `createMock()` with `expects()`** ONLY when verifying method calls
- **NEVER use `#[AllowMockObjectsWithoutExpectations]` or `#[DoesNotPerformAssertions]`** to silence warnings
- **Integration tests** for repositories use `KernelTestCase` and SQLite in-memory database
- **All tests MUST pass with ZERO warnings, ZERO skipped, ZERO deprecated**

#### If coverage is below 100%:

- **DO NOT commit** — fix the missing tests first
- Check for missing edge cases: null values, empty arrays, early returns, exception paths
- Ensure all `if` branches have corresponding tests
- Run `grep "count=\"0\"" var/coverage/clover.xml` to find uncovered lines quickly

#### Reference files:

- Unit test pattern: `tests/Application/NickServ/RegisterThrottleRegistryTest.php`
- Integration test pattern: `tests/Integration/Infrastructure/NickServ/Doctrine/RegisteredNickDoctrineRepositoryTest.php`
- Coverage documentation: `.agents/testing/testing-coverage-priorities.md`

### 1.3.1 Container Lint (CRITICAL — NON-NEGOTIABLE)

**Before running tests, you MUST run the container lint check and ensure it passes.**

```bash
php bin/console lint:container
```

If there are errors:
1. **DO NOT proceed with tests** — fix the container errors first
2. Common errors: wrong argument types, missing services, invalid service definitions
3. Fix and re-run `php bin/console lint:container` until it shows `[OK]`
4. Only THEN proceed with running tests

This ensures the Symfony DI container is valid before any test execution.

### 1.3.2 YAML Lint (CRITICAL — NON-NEGOTIABLE)

**Before running tests, you MUST run the YAML lint check and ensure it passes.**

```bash
php bin/console lint:yaml . --exclude vendor/ --parse-tags
```

If there are errors:
1. **DO NOT proceed with tests** — fix the YAML errors first
2. Common errors: syntax errors, missing quotes, invalid indentation, custom tags without `--parse-tags`
3. Fix and re-run `php bin/console lint:yaml . --exclude vendor/ --parse-tags` until it shows `[OK]`
4. Only THEN proceed with running tests

This ensures all YAML configuration files have valid syntax before any test execution.

---

### 1.4 Parallel Execution Workflow (CRITICAL — PERFORMANCE)

**When implementing new features, EXECUTE TASKS IN PARALLEL whenever possible.**

#### Phase 1: Parallel Exploration (LAUNCH TOGETHER)

Launch multiple tool calls in a SINGLE message to explore simultaneously:

| Agent | Task | Tools |
|-------|------|-------|
| **A** | Find similar commands/handlers | `grep` pattern matching |
| **B** | Find repository interfaces | `glob` + `read` Domain/ |
| **C** | Find translation patterns | `glob` translations/*.yaml |
| **D** | Find test patterns | `glob` tests/**/*Test.php |

```
// Example: Launch 4 parallel searches in ONE message
grep "implements.*CommandInterface" src/  // Agent A
glob "src/Domain/*/Repository/*Interface.php" // Agent B  
glob "translations/*.yaml" // Agent C
glob "tests/Application/**/*Test.php" // Agent D
```

#### Phase 2: Parallel Implementation (AFTER EXPLORATION)

For new commands/services, use Task agents for independent work:

| Task | Parallel? | Reason |
|------|-----------|--------|
| Create Domain entity | ✅ Yes | Independent of other files |
| Create Repository interface | ✅ Yes | Independent |
| Create Command Handler | ✅ Yes | After Phase 1 patterns found |
| Create Test file | ✅ Yes | Can write tests in parallel with implementation |
| Add translations (en + es) | ✅ Yes | Independent files |
| Update services.yaml | ❌ No | Depends on class names created |

**Pattern:** Write implementation and tests simultaneously using multiple tool calls:

```
Single message with:
├── write src/Domain/.../NewEntity.php
├── write src/Application/.../NewHandler.php
├── write tests/Domain/.../NewEntityTest.php
└── write tests/Application/.../NewHandlerTest.php
```

#### Phase 3: Parallel Verification (AFTER IMPLEMENTATION)

Run ALL verifications together in ONE bash command:

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php && \
./vendor/bin/phpunit --no-coverage --display-all-issues && \
./scripts/check-coverage.sh 100
```

#### Parallelization Rules

| Type | Rule |
|------|------|
| **Independent reads** | ALWAYS parallel (multiple tool calls in one message) |
| **Independent writes** | CAN parallel (if no file overlap) |
| **Dependent tasks** | MUST sequential (create → configure → test) |
| **Context preservation** | Each Task agent MUST report findings before proceeding |

#### When NOT to Parallelize

- Files modifying same location (merge conflicts)
- Order-dependent tasks (create entity → create repository → configure DI)
- Debugging sessions needing mental context
- Bug reports requiring sequential log review (see section 1.2)

#### Task Agent Pattern

When launching parallel Task agents, structure prompts like:

```
Task A: Find similar command handlers for REGISTER
- Search: grep "RegisterCommand" src/
- Return: List of files found, patterns identified
- DO NOT modify files

Task B: Find translations for REGISTER
- Search: glob translations/*.yaml + grep "register"
- Return: Translation keys found
- DO NOT modify files
```

After all Tasks complete, THEN proceed with implementation in main agent.

---

## PART 2: ARCHITECTURE & CODING STANDARDS

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
- USE **Typed Constants**: All constants MUST have explicit type declarations. Use `private const string NAME = 'value';` or `private const int LIMIT = 100;`. Untyped constants are forbidden when targeting PHP 8.4 or higher.
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

---

## PART 3: DOMAIN-SPECIFIC SKILLS

For specialized tasks, consult the corresponding skill file:

| Type | Path | When to use |
|------|------|-------------|
| **Testing** | `.agents/testing/` | Tests, coverage, PHPUnit, deprecations |
| **Memory** | `.agents/memory/` | Long-running daemons, memory leaks, Doctrine clear |
| **Protocol** | `.agents/protocol/` | IRCd modules, wire format |
| ├─ Adding IRCd | `.agents/protocol/adding-new-ircd.md` | Checklist for new IRCd support |
| **Services** | `.agents/services/` | Core vs Services, Ports, Bots |
| ├─ Commands | `.agents/services/commands.md` | Creating new IRC service commands |
| ├─ IRCop Permissions | `.agents/services/ircop-commands.md` | IRCop permission system |
| ├─ Debug Actions | `.agents/services/debug-actions.md` | Debug logging for IRCop commands |
| ├─ HELP Design | `.agents/services/help-design.md` | Unified HELP output format |
| └─ New Bot/Service | `.agents/services/adding-new-bot.md` | Checklist for new service |

Each skill has a `README.md` with conventions, rules, and code references.

### 3.1 Quick Reference

**Core vs Services**: The codebase is split into Core (IRCd simulation) and Services (NickServ, ChanServ, etc.). They MUST remain decoupled. Services depend ONLY on Ports and DTOs from `Application/Port/`, never on `Domain\IRC` entities.

**Bots**: Register with `ServiceCommandGateway` (implement `ServiceCommandListenerInterface`), receive commands via `onCommand(senderUid, text)`, delegate ALL business logic to the Service. No Core entity imports in Application layer.

**Protocol**: One module per IRCd (Unreal, InspIRCd). No switch/match over protocol names. Use `ProtocolModuleRegistry` and `ActiveConnectionHolder::getProtocolModule()`.

**Memory**: In daemon loops, call `$em->clear()` after flush, `unset()` large variables, `gc_collect_cycles()` periodically.

**Testing**: PHPUnit 13. Use `createStub()` for unverified mocks, `createMock()` only with `expects()`. Zero warnings/deprecations.

### 3.2 Data Integrity Rules

**Ref Cleanup on Drop (CRITICAL)**: When storing references to registered entities (nicks, channels), you MUST define cleanup behavior when those entities are dropped. This applies to:

**NickDropEvent cleanup required for:**
- `nickId` references → Subscribe to `NickDropEvent`, decide strategy:
  - **CASCADE DELETE**: Remove all referencing entries (e.g., MemoServ ignores, ChanServ ACCESS, OperServ IRCOP)
  - **SET NULL**: Keep entry, null the foreign key (e.g., AKICK creator)
  - **TRANSFER**: Reassign ownership (e.g., channel founder → successor)

**ChannelDropEvent cleanup required for:**
- `channelId` references → Subscribe to `ChannelDropEvent`, typically CASCADE DELETE

**Implementation checklist for new features:**
1. ✅ Does this feature store a `nickId`? → Implement NickDropEvent subscriber
2. ✅ Does this feature store a `channelId`? → Implement ChannelDropEvent subscriber
3. ✅ Add cleanup method to repository interface
4. ✅ Implement in Doctrine repository
5. ✅ Create subscriber implementing `EventSubscriberInterface`
6. ✅ Register in `config/services.yaml` with `kernel.event_subscriber` tag
7. ✅ Write unit tests for subscriber
8. ✅ Write integration tests for repository cleanup method

**When implementing DROP commands (manual drops):**
- NickServ DROP → MUST emit `NickDropEvent` with `reason: 'manual'`
- ChanServ DROP → MUST emit `ChannelDropEvent` with `reason: 'manual'`
- Services MUST NOT delete entities directly without emitting the corresponding DropEvent

**Testing subscribers:**
- Create event instance: `new NickDropEvent(nickId: 123, nickname: 'Test', nicknameLower: 'test', reason: 'manual')`
- Mock repository interfaces with `$this->createMock()` and `expects(self::once())->method('deleteByNickId')->with(123)`
- Verify `getSubscribedEvents()` returns correct event mapping
- Test edge cases: empty results, multiple cascades, null values

**Existing patterns:**
- `MemoServNickDropCleanupSubscriber` → CASCADE DELETE all related data
- `MemoServChannelDropCleanupSubscriber` → CASCADE DELETE all related data
- `ChanServNickDropCleanupSubscriber` → Mixed strategies (DELETE + SET NULL + TRANSFER)
- `OperServNickDropCleanupSubscriber` → CASCADE DELETE IRCOP entry

---

## Engram Persistent Memory — Protocol

You have access to Engram, a persistent memory system that survives across sessions and compactions.

### WHEN TO SAVE (mandatory — not optional)

Call `mem_save` IMMEDIATELY after any of these:
- Bug fix completed
- Architecture or design decision made
- Non-obvious discovery about the codebase
- Configuration change or environment setup
- Pattern established (naming, structure, convention)
- User preference or constraint learned

Format for `mem_save`:
- **title**: Verb + what — short, searchable (e.g. "Fixed N+1 query in UserList", "Chose Zustand over Redux")
- **type**: bugfix | decision | architecture | discovery | pattern | config | preference
- **scope**: `project` (default) | `personal`
- **topic_key** (optional, recommended for evolving decisions): stable key like `architecture/auth-model`
- **content**:
  **What**: One sentence — what was done
  **Why**: What motivated it (user request, bug, performance, etc.)
  **Where**: Files or paths affected
  **Learned**: Gotchas, edge cases, things that surprised you (omit if none)

Topic rules:
- Different topics must not overwrite each other (e.g. architecture vs bugfix)
- Reuse the same `topic_key` to update an evolving topic instead of creating new observations
- If unsure about the key, call `mem_suggest_topic_key` first and then reuse it
- Use `mem_update` when you have an exact observation ID to correct

### WHEN TO SEARCH MEMORY

When the user asks to recall something — any variation of "remember", "recall", "what did we do",
"how did we solve", "recordar", "acordate", "qué hicimos", or references to past work:
1. First call `mem_context` — checks recent session history (fast, cheap)
2. If not found, call `mem_search` with relevant keywords (FTS5 full-text search)
3. If you find a match, use `mem_get_observation` for full untruncated content

Also search memory PROACTIVELY when:
- Starting work on something that might have been done before
- The user mentions a topic you have no context on — check if past sessions covered it
- The user's FIRST message references the project, a feature, or a problem — call `mem_search` with keywords from their message to check for prior work before responding

### SESSION CLOSE PROTOCOL (mandatory)

Before ending a session or saying "done" / "listo" / "that's it", you MUST:
1. Call `mem_session_summary` with this structure:

## Goal
[What we were working on this session]

## Instructions
[User preferences or constraints discovered — skip if none]

## Discoveries
- [Technical findings, gotchas, non-obvious learnings]

## Accomplished
- [Completed items with key details]

## Next Steps
- [What remains to be done — for the next session]

## Relevant Files
- path/to/file — [what it does or what changed]

This is NOT optional. If you skip this, the next session starts blind.

### AFTER COMPACTION

If you see a message about compaction or context reset, or if you see "FIRST ACTION REQUIRED" in your context:
1. IMMEDIATELY call `mem_session_summary` with the compacted summary content — this persists what was done before compaction
2. Then call `mem_context` to recover any additional context from previous sessions
3. Only THEN continue working

Do not skip step 1. Without it, everything done before compaction is lost from memory.