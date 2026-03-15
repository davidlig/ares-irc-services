# Testing skill

Use this skill when working on tests, coverage, or test prioritisation. Full detail: [testing-coverage-priorities.md](testing-coverage-priorities.md).

## CRITICAL RULES

**CRITICAL RULE (Zero Tolerance for Test Issues):** Tests must be PRISTINE. It is NON-NEGOTIABLE that test executions result in ZERO warnings, ZERO skipped tests, ZERO incomplete tests, and ZERO deprecations. Always execute tests using the flag `--display-all-issues`. If any PHPUnit-specific issues, deprecations, or warnings appear, read `composer.json` to identify the exact PHPUnit version installed, search the official PHPUnit documentation for that specific version using your web search capabilities, and apply the correct modern syntax to fix them immediately. Mocks must be exact and no test can be bypassed.

**CRITICAL RULE (Coverage Analysis):** When checking for test coverage gaps, you MUST always generate the coverage report and analyze the `var/coverage/clover.xml` file. Use the `<metrics>` and `<line>` tags inside this XML to identify exactly which classes, methods, and lines lack coverage before writing any new tests.

**CRITICAL RULE (Test Doubles & Mocks):** NEVER use the `#[AllowMockObjectsWithoutExpectations]` or `#[DoesNotPerformAssertions]` attributes to silence PHPUnit warnings.

If a test double is only needed to fulfill a dependency or provide a canned response without verifying behavior, you MUST use `$this->createStub(ClassName::class)` instead of `createMock()`.

If a test double's behavior must be verified (e.g., ensuring a specific method is called), you MUST use `$this->createMock(ClassName::class)` AND write explicit expectations (e.g., `->expects($this->once())->method(...)`).

## PHPUnit 13 Notices and Deprecations (PHPUnit 13.x)

### The 'N' Character in Test Output

When running tests, you may see `N` characters in the progress output:

...........N.NN.........N.N......

Each `N` represents a test that triggered a **PHPUnit Notice**. To identify which tests have notices:

1. Run with `--testdox` to see test names:
   ./vendor/bin/phpunit tests/Path/To/TestFile.php --no-coverage --testdox

2. Count the positions: `....N` means the 5th test has a notice.

3. Use `--list-tests` to correlate:
   ./vendor/bin/phpunit tests/Path/To/TestFile.php --no-coverage --list-tests

### Common PHPUnit Notice Causes and Fixes

#### 1. `createMock()` Without Expectations (Most Common)

**Problem:** Using `createMock()` but only configuring `method()` without `expects()`:

// WRONG - Generates notice
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);
// No $repo->expects(...) anywhere

**Fix A - If you DON'T need to verify calls:** Use `createStub()` instead:

// CORRECT - No notice
$repo = $this->createStub(SomeRepository::class);
$repo->method('find')->willReturn($result);

**Fix B - If you DO need to verify calls:** Add `expects()`:

// CORRECT - No notice (verifying behavior)
$repo = $this->createMock(SomeRepository::class);
$repo->expects(self::once())->method('find')->willReturn($result);

#### 2. Mixed Mock/Stub Pattern (Common Pattern)

**Problem:** Mock has multiple methods, only some need verification:

// WRONG - Generates notice for `method()` without `expects()`
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);        // No expects() - NOTICE!
$repo->expects(self::once())->method('save');      // Has expects() - OK
$eventDispatcher = $this->createMock(EventDispatcherInterface::class);
$eventDispatcher->expects(self::never())->method('dispatch');  // OK

**Fix:** For methods that just return values, use `createMock` with `expects(self::any())`:

// CORRECT - but generates DEPRECATION in PHPUnit 13
$repo->expects(self::any())->method('find')->willReturn($result);

**BETTER Fix for PHPUnit 13+:** Use `createMock` for the methods you verify, `createStub` for those you don't:

// CORRECT - No notice, no deprecation
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // createMock allows method() without expects()
$repo->expects(self::once())->method('save');  // Method being verified

Wait - this still causes notices! The real fix:

// BEST - Use createStub for unverified methods, keep createMock only for verified
$findResult = $this->createStub(SomeEntity::class);
$repo = $this->createMock(SomeRepository::class);
$repo->expects(self::any())->method('find')->willReturn($findResult);  // Deprecated!
$repo->expects(self::once())->method('save');

**ACTUALLY CORRECT for PHPUnit 13:**

// The createMock with just method() and willReturn() WITHOUT expects() is OK
// The problem is when you createMock and DON'T use expects() anywhere
// If you need ANY expects(), add expects(self::any()) to others

// Option 1: All stubs (no verification needed anywhere)
$repo = $this->createStub(SomeRepository::class);
$repo->method('find')->willReturn($result);

// Option 2: Mix of verification + stubbing
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // THIS CAUSES NOTICE in PHPUnit 13!
$repo->expects(self::once())->method('save');  // This has expects

// FIX for Option 2:
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // Remove this line or add expects
// Instead, just don't configure unverified methods - PHPUnit returns null by default
$repo->expects(self::once())->method('save');

**SIMPLEST FIX:** When a mock has ANY method with `expects()`, ALL methods must have `expects()`:

// WRONG - Mixed pattern causes notice
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);        // No expects - NOTICE!
$repo->expects(self::once())->method('save');      // Has expects

// CORRECT - All methods have expects() or use stub
$repo = $this->createMock(SomeRepository::class);
$repo->expects(self::any())->method('find')->willReturn($result);  // Any is deprecated!
$repo->expects(self::once())->method('save');

**ACTUAL CORRECT FIX (PHPUnit 13):** Just use `->method()` without `expects()`:

// CORRECT for PHPUnit 13 - method() alone returns null by default
// If you need a return value AND verification, structure it like:
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // This alone is OK if no other expects()
// BUT if you add ANY expects() on another method, this causes notice!

// So the REAL pattern is:
$repo = $this->createMock(SomeRepository::class);
// Don't configure methods you don't verify - they return null/empty arrays
$repo->expects(self::once())->method('save')->with($entity);

**FINAL ANSWER - Two Patterns:**

// PATTERN A: Stub only (no verification)
$repo = $this->createStub(SomeRepository::class);
$repo->method('find')->willReturn($result);
$repo->method('findBy')->willReturn([]);

// PATTERN B: Mock with verification (PHPUnit 13 compatible)
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // OK if no other expects()
// If you need to verify save():
$repo->method('find')->willReturn($result);
$repo->expects(self::once())->method('save');  // This triggers notice for 'find'!

// FIX: Use willReturnCallback for unverified methods when ANY expects exists
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // DEPRECATED - use stub pattern
// OR: Add expects to ALL configured methods:
$repo->expects(self::any())->method('find')->willReturn($result);  // Deprecated!
$repo->expects(self::once())->method('save');

### Deprecation: `expects(self::any())`

**Problem:** PHPUnit 13+ deprecates `expects(self::any())`:

The any() invoked count expectation is deprecated and will be removed in PHPUnit 14.
Use a test stub instead or configure a real invocation count expectation.

**Fix:** Don't use `expects(self::any())`. Instead:

// DEPRECATED
$repo->expects(self::any())->method('find')->willReturn($result);

// CORRECT - Use createStub if no verification needed
$repo = $this->createStub(SomeRepository::class);
$repo->method('find')->willReturn($result);

// CORRECT - Or just use method() without expects()
$repo = $this->createMock(SomeRepository::class);
$repo->method('find')->willReturn($result);  // OK when no other expects()

### Step-by-Step Debugging Process

1. **Run the failing test file:**
   ./vendor/bin/phpunit tests/Path/TestFile.php --no-coverage

2. **If you see 'N' characters, run with debug:**
   ./vendor/bin/phpunit tests/Path/TestFile.php --no-coverage --testdox

3. **Check for ALL PHPUnit issues (Strict Mode):**
   ./vendor/bin/phpunit tests/Path/TestFile.php --no-coverage --display-all-issues

4. **Find patterns in the test file:**
   grep -n "createMock" tests/Path/TestFile.php

5. **For each `createMock`, check if:**
   - There's at least one `->expects(...)` on the mock
   - ALL configured methods have `->expects(...)` or NONE have it

6. **Apply fix:**
   - If NO verification needed -> change to `createStub()`
   - If MIXED (some verified, some not) -> remove unverified method configurations OR change to `createStub()`

### Quick Reference

| Scenario | Pattern | Fix |
|----------|---------|-----|
| No verification needed | `createMock()` + `method()` | `createStub()` |
| Need to verify calls | `createMock()` + `expects(once/never)` | Keep as-is |
| Mixed (some verified, some not) | `createMock()` + `method()` + `expects()` | Use `createMock` only for verified, unconfigured for others |
| Deprecated `expects(any())` | `expects(self::any())` | Remove or use `createStub()` |

## Coverage

- Requires **PCOV** or **Xdebug**. Check: `php -m | grep -E 'pcov|xdebug'`.
- Generate report: `./vendor/bin/phpunit --coverage-text --coverage-filter=src`.
- Reports: `var/coverage/` (HTML, Clover).

## Test conventions

- **PHPUnit 13**: attributes `#[Test]`, `#[CoversClass(ClassUnderTest::class)]`.
- Layout: `tests/` mirrors `src/` (Domain, Application, Infrastructure, UI).
- **final** classes cannot be mocked: use **interfaces** in production and stubs/mocks of the interface in tests, or **test subclasses** that override only the needed method (e.g. void `run()`).
- **void** methods: do not use `willReturn(null)` in mocks; use `willReturnCallback(static function (): void {})` or a subclass that overrides the method.

## Priorities for new tests

1. **Done:** ConnectCommand (UI/CLI), ConnectToServerHandler, Protocol handlers (Unreal, InspIRCd).
2. **Next:** Protocol service actions / introduction / vhost.
3. **Then:** In-memory registries (Infrastructure), Doctrine repos (integration), subscribers/bots.

## Useful commands

./vendor/bin/phpunit --no-coverage
./vendor/bin/phpunit --display-all-issues
./vendor/bin/phpunit tests/Domain --no-coverage
./vendor/bin/phpunit tests/Application --no-coverage
./vendor/bin/phpunit --coverage-text --coverage-filter=src

## Maintenance

When adding or changing tests: update the test count in the project **README.md** (Testing section) and, if relevant, the "Covered" / "Uncovered" sections in [testing-coverage-priorities.md](testing-coverage-priorities.md).
