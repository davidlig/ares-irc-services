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
composer yaml-fix
composer yaml-check

./scripts/check-coverage.sh 100 --issues
```

Also run the configured architecture dependency gate.

Install the pinned YAML formatter with `npm ci` using Node 24/npm for development and CI only.
Run `composer yaml-fix` immediately after PHP-CS-Fixer. The npm equivalents are `npm run yaml:fix`
and `npm run yaml:check`; they use the locally installed, locked Prettier without implicit downloads.
Both commands cover all `.yaml` and `.yml` files under `config/`, `translations/` and
`.github/workflows/`, plus root `deptrac.yaml`. Review formatting changes and run the non-mutating
`composer yaml-check` before
completion. For bulk formatting, compare before/after Symfony-parsed values, types and custom tags
throughout the scope, check idempotence, and confirm check mode rejects violations without writing.
Preserve physical multiline blocks, `!irc`, IRC escapes, placeholders, meaningful whitespace,
mail-body terminal newlines, Deptrac rules and workflow behavior/scripts/permissions. Do not change
translated messages or expand the
staged Spanish-first HELP rollout.

Use identical English technical section comments at equivalent structural positions across locales.
NickServ and ChanServ HISTORY sections use `# --- HISTORY ---`, `# --- HISTORY MESSAGES ---`, and
`# --- HISTORY EXTRA FIELDS ---` before `history`, `history.message`, and `history.extra`.
Allow Prettier-selected quoting only when interpreted content is unchanged; retain double quotes
where YAML escape decoding is required.

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
