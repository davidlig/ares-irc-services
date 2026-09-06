# Testing & Verification

Use for all production changes.

## 1. Strict PHPUnit contract

Tests must produce:
- zero failures;
- zero warnings;
- zero notices;
- zero skipped;
- zero incomplete;
- zero risky;
- zero deprecations.

Use coverage metadata appropriately.

Do not suppress test issues.

## 2. Coverage

The project requires 100% line coverage:

```bash
./scripts/check-coverage.sh 100 --issues
```

Run the full coverage suite exactly once after implementation is complete.

During development use focused tests without coverage:

```bash
./vendor/bin/phpunit --no-coverage --display-all-issues tests/.../ChangedTest.php
```

## 3. Test doubles

Use `createStub()` for return values only.

Use `createMock()` only when asserting interactions with `expects()`.

Prefer small fakes for stateful ports when clearer.

## 4. Test layout

```text
tests/
├── Irc/
│   ├── Domain/
│   ├── Application/
│   └── Adapter/
│       └── Protocol/
│           ├── InspIRCd/
│           ├── UnrealStandalone/
│           └── UnrealUdb/
├── NickServ/{Domain,Application,Adapter}
├── ChanServ/{Domain,Application,Adapter}
├── MemoServ/{Domain,Application,Adapter}
├── OperServ/{Domain,Application,Adapter}
├── Shared/
├── Bootstrap/
└── Architecture/
```

There is no separate UDB Domain/Application test tree.

## 5. What to test

### Domain
Invariants, transitions, policies, value objects.

### Application
Use-case outcomes and port interactions using typed inputs.

No:
- IRC Context;
- Symfony boot;
- Doctrine;
- wire types;
- translation assertions.

### Adapter/In
Parsing, mapping, command metadata, presenter integration, framework event translation.

### Adapter/Out
External technology mapping and persistence integration.

### Protocol
Wire behavior, handshake, semantic actions, state machines, duplicates, malformed input, deadlines,
disconnect/reset.

## 6. Architecture tests

Architecture tests enforce:
- Domain purity;
- Application independence from adapters/frameworks;
- context boundaries;
- no cross-context Domain imports;
- no wire types escaping;
- no protocol-name branching in service inner layers;
- no `Udb` bounded-context namespaces;
- no dependency between `UnrealStandalone` and `UnrealUdb`.

Do not weaken architecture tests to permit a new violation.

## 7. Determinism

No sleeps in unit tests.

Inject/fake:
- time;
- scheduler;
- randomness;
- external network/mail outputs.

## 8. Live validation

Live IRC/MariaDB checks are additive and only used when safe tooling exists.

Never destructively test real user/channel resources.
Never expose secrets in reports.
Live checks do not replace PHPUnit/integration coverage.

## 9. Refactor test rule

Preserve behavior assertions.

Do not replace meaningful tests with tests of the new internal file/class shape.
Use architecture tests for structure and behavior tests for semantics.
