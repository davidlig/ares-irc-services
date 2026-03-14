# Testing skill

Use this skill when working on tests, coverage, or test prioritisation. Full detail: [testing-coverage-priorities.md](testing-coverage-priorities.md).

## CRITICAL RULES

**CRITICAL RULE (Deprecations):** Tests must NEVER contain deprecations. Always execute tests using the flags `--display-deprecations --display-phpunit-deprecations`. If PHPUnit-specific deprecations appear, read `composer.json` to identify the exact PHPUnit version installed, search the official PHPUnit documentation for that specific version using your web search capabilities, and apply the correct modern syntax to fix them.

**CRITICAL RULE (Coverage Analysis):** When checking for test coverage gaps, you MUST always generate the coverage report and analyze the `var/coverage/clover.xml` file. Use the `<metrics>` and `<line>` tags inside this XML to identify exactly which classes, methods, and lines lack coverage before writing any new tests.

## Coverage

- Requires **PCOV** or **Xdebug**. Check: `php -m | grep -E 'pcov|xdebug'`.
- Generate report: `./vendor/bin/phpunit --coverage-text --coverage-filter=src`.
- Reports: `var/coverage/` (HTML, Clover).

## Test conventions

- **PHPUnit 13**: attributes `#[Test]`, `#[CoversClass(ClassUnderTest::class)]`.
- Layout: `tests/` mirrors `src/` (Domain, Application, Infrastructure, UI).
- **final** classes cannot be mocked: use **interfaces** in production and stubs/mocks of the interface in tests, or **test subclasses** that override only the needed method (e.g. void `run()`).
- **void** methods: do not use `willReturn(null)` in mocks; use `willReturnCallback(static function (): void {})` or a subclass that overrides the method.
- If there are mocks with no expectations (only construction dependencies): add `#[AllowMockObjectsWithoutExpectations]` on the test class.

## Priorities for new tests

1. **Done:** ConnectCommand (UI/CLI), ConnectToServerHandler, Protocol handlers (Unreal, InspIRCd).
2. **Next:** Protocol service actions / introduction / vhost.
3. **Then:** In-memory registries (Infrastructure), Doctrine repos (integration), subscribers/bots.

## Useful commands

```bash
./vendor/bin/phpunit --no-coverage
./vendor/bin/phpunit --display-deprecations --display-phpunit-deprecations
./vendor/bin/phpunit tests/Domain --no-coverage
./vendor/bin/phpunit tests/Application --no-coverage
./vendor/bin/phpunit --coverage-text --coverage-filter=src
```

## Maintenance

When adding or changing tests: update the test count in the project **README.md** (Testing section) and, if relevant, the “Covered” / “Uncovered” sections in [testing-coverage-priorities.md](testing-coverage-priorities.md).
