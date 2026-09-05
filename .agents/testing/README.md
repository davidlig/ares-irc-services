# Testing Skill

Use this skill when working on tests, coverage, or test prioritisation.

## CRITICAL RULES

### Zero Tolerance for Test Issues

Tests MUST be PRISTINE. It is NON-NEGOTIABLE that test executions result in ZERO warnings, ZERO skipped tests, ZERO incomplete tests, ZERO risky tests, and ZERO deprecations. `phpunit.dist.xml` enforces these outcomes and requires coverage metadata; always run with `--display-all-issues`.

### Test Doubles: Stub vs Mock

**Use `createStub()`** when you only need to provide return values (no behavior verification):

```php
$repo = $this->createStub(SomeRepositoryInterface::class);
$repo->method('find')->willReturn($result);
```

**Use `createMock()` with `expects()`** ONLY when verifying method calls:

```php
$repo = $this->createMock(SomeRepositoryInterface::class);
$repo->expects(self::once())->method('save')->with($entity);
```

**NEVER** use `createMock()` without `expects()` — triggers PHPUnit 13 notice.
**NEVER** use `#[AllowMockObjectsWithoutExpectations]` or `#[DoesNotPerformAssertions]` to silence warnings.

### PHPUnit 13 Notes

- `expects(self::any())` is DEPRECATED — use `createStub()` instead
- `N` in output = PHPUnit notice (usually mock without expects)
- Debug with `--testdox` and `--list-tests` to identify the test causing the notice

## Coverage

- Requires **PCOV** or **Xdebug**: `php -m | grep -E 'pcov|xdebug'`
- **While writing tests:** after finishing new or modified test files, run exactly those files with `./vendor/bin/phpunit --no-coverage --display-all-issues Test1.php Test2.php ...`. Repeat focused runs as needed; do not run the full suite.
- **Only when the whole implementation is complete:** run `./scripts/check-coverage.sh 100 --issues` once. It runs the full PHPUnit suite WITH coverage, enforces the gate, and writes `var/coverage/clover.xml`.
- NEVER run `check-coverage.sh` after each test file or sub-feature, and NEVER run a standalone full suite immediately before or after it.
- Find uncovered: `grep 'count="0"' var/coverage/clover.xml`

## Test Conventions

- **PHPUnit 13 attributes**: `#[CoversClass(ClassUnderTest::class)]`, `#[Test]`; every test must declare coverage metadata because `requireCoverageMetadata="true"` is enabled
- Layout: `tests/` mirrors `src/` (Domain, Application, Infrastructure, UI, Integration)
- **final** classes cannot be mocked — use interfaces or test subclasses
- **void** methods: use `willReturnCallback(static function (): void {})` not `willReturn(null)`

## Useful Commands

```bash
# Full verification (tests + coverage gate, suite runs ONCE) — the final gate
./scripts/check-coverage.sh 100 --issues

# Focused runs immediately after writing new/modified tests
./vendor/bin/phpunit --no-coverage --display-all-issues tests/Domain/FooTest.php
./vendor/bin/phpunit --no-coverage --display-all-issues tests/Application/FooTest.php tests/Application/BarTest.php
```

## Related Skills

- `.agents/testing/testing-patterns.md` — Patterns by layer/type
- `.agents/testing/testing-coverage-priorities.md` — Test priorities map
- `.agents/workflow.md` — Pre-commit verification chain
