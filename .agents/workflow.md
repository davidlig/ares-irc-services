# Agent Workflow

Use for investigation, implementation, parallel work, validation, and commits.

## 1. Start from ownership

Before coding:
- inspect the affected bounded context/protocol adapter;
- inspect direct callers/consumers;
- inspect relevant tests and DI;
- load only relevant `.agents` skills.

Directory placement follows architecture, never convenience.

## 2. Bug investigation

1. inspect relevant logs when available;
2. inspect recent changes when regression is plausible;
3. identify the failing invariant;
4. trace the symptom to the owning responsibility;
5. fix the owner, not the first suppressible symptom.

Do not add ignore annotations/null guards until the invalid state is understood.

## 3. Planning

For architectural work establish:
- scope;
- invariants;
- ownership;
- dependency changes;
- tests;
- final verification.

If implementation was requested, continue through implementation rather than stopping after a plan.

## 4. Parallel work

Parallelize:
- independent reads;
- searches;
- analysis;
- independent files after contracts are stable.

Do not parallelize:
- same-file writes;
- dependent port/consumer changes;
- overlapping namespace changes;
- shared mutable state-machine extraction;
- DI wiring before ownership is final.

## 5. Refactoring

Refactor by responsibility, not file size.

Before extracting a class identify:
- state owned;
- invariant protected;
- dependency direction;
- independent reason to change.

Avoid one-method forwarding chains.

Do not deduplicate `UnrealStandalone` and `UnrealUdb` behavior at the cost of coupling.

## 6. Documentation lookup

Prefer:
1. repository code/config for project conventions;
2. official/current external docs;
3. tests/specifications;
4. model memory for stable background only.

For protocol work use exact-version IRCd/module documentation.

## 7. Development loop

Run focused tests:

```bash
./vendor/bin/phpunit --no-coverage --display-all-issues tests/.../ChangedTest.php
```

Use scoped PHPStan during difficult work if useful.

## 8. Final verification

```bash
php -l path/to/modified.php

php bin/console lint:container

php bin/console lint:yaml . --exclude vendor/ --parse-tags

./vendor/bin/phpstan analyse src/ tests/ --level=max --error-format=raw --no-progress

./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

./scripts/check-coverage.sh 100 --issues
```

Also run the configured architecture dependency gate.

Inspect formatting changes before completion.

Never report an unexecuted gate as passed.

## 9. Documentation consistency

Update README/config docs for user-visible command/config/support changes.

Update agent skills only for architectural/workflow contract changes.

Do not use `.agents` as a changelog or status report.

## 10. Commits

Use English Conventional Commits.

Examples:

```text
refactor(nickserv): separate IRC commands from application use cases

refactor(protocol): isolate UnrealUdb reconciliation state

fix(chanserv): preserve secure rank policy during channel sync

test(unreal-udb): cover reconciliation absolute timeout
```

Do not mention AI agents in commit messages.
