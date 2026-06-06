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
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php && \
./vendor/bin/phpunit --no-coverage --display-all-issues && \
./scripts/check-coverage.sh 100
```

If any step fails, fix it and re-run from the failed step — never skip ahead.

**Commit order:** implement → php-cs-fixer → commit. Never commit unformatted code.

---

## 3. Test Coverage (NON-NEGOTIABLE)

**100% coverage on ALL new code. No exceptions.**

- Every new class MUST have tests with `#[CoversClass(ClassName::class)]`
- Every public method MUST have at least one test
- Every branch/condition MUST be tested
- Run `./scripts/check-coverage.sh 100` before claiming completion
- Use `createStub()` for unverified dependencies, `createMock()` ONLY with `expects()`
- Zero warnings, zero skipped, zero deprecated, zero incomplete

---

## 4. Immutability & Readonly

- **Value Objects**, **DTOs**, **Commands**, **Domain Events**: MUST be `readonly class`
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

<!-- code-review-graph MCP tools -->
## MCP Tools: code-review-graph

**IMPORTANT: This project has a knowledge graph. ALWAYS use the
code-review-graph MCP tools BEFORE using Grep/Glob/Read to explore
the codebase.** The graph is faster, cheaper (fewer tokens), and gives
you structural context (callers, dependents, test coverage) that file
scanning cannot.

### When to use graph tools FIRST

- **Exploring code**: `semantic_search_nodes` or `query_graph` instead of Grep
- **Understanding impact**: `get_impact_radius` instead of manually tracing imports
- **Code review**: `detect_changes` + `get_review_context` instead of reading entire files
- **Finding relationships**: `query_graph` with callers_of/callees_of/imports_of/tests_for
- **Architecture questions**: `get_architecture_overview` + `list_communities`

Fall back to Grep/Glob/Read **only** when the graph doesn't cover what you need.

### Key Tools

| Tool | Use when |
| ------ | ---------- |
| `detect_changes` | Reviewing code changes — gives risk-scored analysis |
| `get_review_context` | Need source snippets for review — token-efficient |
| `get_impact_radius` | Understanding blast radius of a change |
| `get_affected_flows` | Finding which execution paths are impacted |
| `query_graph` | Tracing callers, callees, imports, tests, dependencies |
| `semantic_search_nodes` | Finding functions/classes by name or keyword |
| `get_architecture_overview` | Understanding high-level codebase structure |
| `refactor_tool` | Planning renames, finding dead code |

### Workflow

1. The graph auto-updates on file changes (via hooks).
2. Use `detect_changes` for code review.
3. Use `get_affected_flows` to understand impact.
4. Use `query_graph` pattern="tests_for" to check coverage.
