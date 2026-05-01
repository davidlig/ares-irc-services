# Testing Skill

Use this skill when working on tests, coverage, or test prioritisation.

## CRITICAL RULES

### Zero Tolerance for Test Issues

Tests MUST be PRISTINE. It is NON-NEGOTIABLE that test executions result in ZERO warnings, ZERO skipped tests, ZERO incomplete tests, and ZERO deprecations. Always run with `--display-all-issues`.

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
- Generate: `./vendor/bin/phpunit --coverage-text --coverage-filter=src`
- Reports: `var/coverage/` (HTML, Clover)
- Check: `./scripts/check-coverage.sh 100`
- Find uncovered: `grep 'count="0"' var/coverage/clover.xml`

## Test Conventions

- **PHPUnit 13 attributes**: `#[CoversClass(ClassUnderTest::class)]`, `#[Test]`
- Layout: `tests/` mirrors `src/` (Domain, Application, Infrastructure, UI, Integration)
- **final** classes cannot be mocked — use interfaces or test subclasses
- **void** methods: use `willReturnCallback(static function (): void {})` not `willReturn(null)`

## Useful Commands

```bash
./vendor/bin/phpunit --no-coverage
./vendor/bin/phpunit --display-all-issues
./vendor/bin/phpunit tests/Domain --no-coverage
./vendor/bin/phpunit tests/Application --no-coverage
./vendor/bin/phpunit --coverage-text --coverage-filter=src
./scripts/check-coverage.sh 100
```

## Related Skills

- `.agents/testing/testing-patterns.md` — Patterns by layer/type
- `.agents/testing/testing-coverage-priorities.md` — Test priorities map
- `.agents/workflow.md` — Pre-commit verification chain
