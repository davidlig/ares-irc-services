# AI Assistant Workflow & Operations

Use this skill for the project's operational workflow rules: parallel execution, pre-commit verification, and bug investigation.

---

## 1. Golden Rules (NON-NEGOTIABLE)

### Parallelize EVERYTHING by Default

Launch multiple independent operations in a SINGLE message:

- Reading multiple files → Multiple `read` tool calls in one message
- Searching for patterns → Multiple `grep`/`glob` in one message
- Exploring different areas → Multiple task agents in one message
- Writing independent files → Multiple `write` tool calls in one message

```
Pattern: ONE message with multiple tool calls
├── read file1.php          │
├── read file2.php          │  All execute in parallel
├── grep "pattern" src/      │  → Faster results
└── glob "**/*.yaml"        │
```

#### When NOT to Parallelize

| Situation | Reason |
|-----------|--------|
| Sequential dependencies | B depends on result of A |
| Same file modifications | Race conditions, merge conflicts |
| Debugging with mental context | Requires sequential reasoning |
| Bug investigation (logs) | Must correlate events in order |

### Rules Summary

1. **Read operations**: ALWAYS parallel (multiple files in one message)
2. **Search operations**: ALWAYS parallel (multiple patterns in one message)
3. **Write operations**: CAN parallel if files don't overlap
4. **Dependent tasks**: MUST be sequential (wait for result before next step)
5. **Task agents**: Use for exploration in parallel before implementation

---

## 2. Development Workflow

- **Think Before Coding**: Present a structured step-by-step plan or pseudocode before writing code. Wait for approval before generating code.
- **Refactoring**: Before adding features, analyze for architectural violations and code smells (especially "Long Methods" or SRP violations). If a method has too much logic, propose refactoring via "Extract Method" before proceeding.
- **Code Style (PHP CS Fixer)**: All code MUST comply with `.php-cs-fixer.dist.php`. Format code or ensure alignment with strict Symfony standards and PHP 8.4 features.
- **Commit Order (CRITICAL)**: Run PHP CS Fixer BEFORE committing: (1) implement code, (2) run `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php`, (3) then commit. Never commit first and fix style separately.
- **Git Management**: Commit when task is done. Messages MUST be in English, follow Conventional Commits (`feat:`, `fix:`, `refactor:`).
- **README Sync**: If you add/change/remove features, commands, or config vars, update `README.md`.
- **Translations (CRITICAL)**: When adding translatable strings, create translations for ALL languages: `en` and `es`. Files at `translations/<service>.en.yaml` and `translations/<service>.es.yaml`. Every key in `.en.yaml` MUST exist in `.es.yaml`, and vice versa.

---

## 3. Bug Investigation Workflow

When the user reports an error, bug, or unexpected behaviour:

1. **Read application logs** under `var/log/*.log` (e.g., `irc-*.log`, `ares-*.log`, `maintenance-*.log`)
2. **Review recent commits** (`git log` or commit history) for regressions
3. If a specific commit is mentioned, inspect with `git show <hash>`
4. Correlate log lines and diff with the user's description
5. Form a hypothesis, then search the codebase or implement the fix

Never skip log review when debugging reported errors.

---

## 4. Pre-Commit Verification Order (NON-NEGOTIABLE)

Run verifications in this EXACT order before committing:

```bash
# Step 1: PHP syntax check (on modified files)
php -l path/to/file.php

# Step 2: Verify container is valid
php bin/console lint:container

# Step 3: Verify YAML files are valid
php bin/console lint:yaml . --exclude vendor/ --parse-tags

# Step 4: Format code
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

# Step 5: Run tests
./vendor/bin/phpunit --no-coverage --display-all-issues

# Step 6: Check coverage
./scripts/check-coverage.sh 100
```

**Why this order:**
- `php -l` catches syntax errors instantly
- `lint:container` catches DI errors early
- `lint:yaml` catches configuration errors
- `php-cs-fixer` ensures consistent style
- `phpunit` validates functionality
- `coverage` ensures code is tested

If any step fails, do NOT proceed. Fix the error, re-run the failed step, and only continue when it passes.

**Single command for phases 2-6:**

```bash
php bin/console lint:container && \
php bin/console lint:yaml . --exclude vendor/ --parse-tags && \
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php && \
./vendor/bin/phpunit --no-coverage --display-all-issues && \
./scripts/check-coverage.sh 100
```

---

## 5. Parallel Execution Workflow for New Features

### Phase 1: Parallel Exploration

Launch multiple searches in ONE message:

| Search | Pattern |
|--------|---------|
| Find similar commands/handlers | `grep "implements.*CommandInterface" src/` |
| Find repository interfaces | `glob "src/Domain/*/Repository/*Interface.php"` |
| Find translation patterns | `glob "translations/*.yaml"` |
| Find test patterns | `glob "tests/Application/**/*Test.php"` |

### Phase 2: Parallel Implementation

Write implementation and tests simultaneously:

```
Single message with:
├── write src/Domain/.../NewEntity.php
├── write src/Application/.../NewHandler.php
├── write tests/Domain/.../NewEntityTest.php
└── write tests/Application/.../NewHandlerTest.php
```

### Phase 3: Parallel Verification

Run all verifications together (order matters, use `&&`):

```bash
php bin/console lint:container && \
php bin/console lint:yaml . --exclude vendor/ --parse-tags && \
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php && \
./vendor/bin/phpunit --no-coverage --display-all-issues && \
./scripts/check-coverage.sh 100
```

### Parallelization Rules

| Type | Rule |
|------|------|
| Independent reads | ALWAYS parallel |
| Independent writes | CAN parallel (if no file overlap) |
| Dependent tasks | MUST sequential |
| Task agents | Use for exploration in parallel |

### Task Agent Pattern

When launching parallel task agents:

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

After all tasks complete, proceed with implementation.
