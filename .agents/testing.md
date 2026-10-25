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
Require exact equality of covered statements and total statements; a rounded display of 100% is not
proof of complete coverage. Do not lower thresholds, narrow the source filter or add exclusions to pass.

During development use focused tests without coverage:

```bash
./vendor/bin/phpunit --no-coverage --display-all-issues tests/.../ChangedTest.php
```

### Isolated UnrealUdb verification

For isolated UDB checks, use the owned bootstrap below. It loads the autoloader and synthetic test
configuration without Symfony Dotenv or repository `.env` files; do not substitute local credentials.
The ordinary runner above remains appropriate for other project checks.

```bash
./vendor/bin/phpunit --no-coverage --display-all-issues \
  --bootstrap tests/Irc/Adapter/Protocol/UnrealUdb/BootstrapIsolated.php \
  tests/Irc/Adapter/Protocol/UnrealUdb/ChangedTest.php
```

Replace `ChangedTest.php` with the affected existing test path. At final isolated verification, run the
full original gate once, retaining its project-wide source coverage and zero-issue requirements:

```bash
./scripts/check-coverage.sh 100 --issues \
  --bootstrap tests/Irc/Adapter/Protocol/UnrealUdb/BootstrapIsolated.php
```

Cover coherent snapshot roots, typed schema/limits, independent native digest oracle vectors,
service-operation regressions and malformed/duplicate/foreign/stale ACK and transport-barrier cases.
Exercise the real handler route as well as coordinator boundaries. Preserve source and oracle semantics;
do not shadow built-ins or manufacture impossible failures to satisfy coverage. Controlled disposable
stream fixtures may exercise genuine I/O faults; restore wrappers/error handlers and retain failure assertions.

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

### Translation catalog smoke tests

`tests/Shared/Translations/TranslationCatalogTest.php` checks English (`en`) only.
Keep it limited to catalog loading and essential key/placeholder checks. Do not add
cross-locale comparisons, exhaustive prose snapshots, or color, padding, and line-wrapping
assertions. Do not move removed checks to another test just to retain them.
The class uses `CoversNothing`; production line coverage comes from focused behavior tests,
such as the IRC YAML loader tests, not additional catalog assertions. Continue reviewing all
14 supported locales when changing translations.

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

### Disposable native UDB integration

Follow [unreal-udb.md](unreal-udb.md) for the native protocol contract. When native execution is explicitly
authorized, `integration/udb4/run_native.py` accepts `--source-root` for the selected canonical UnrealIRCd
tree and `--work-parent` for an external disposable workspace. Keep canonical source/configuration,
binaries and index immutable; never compile or reconfigure in place. The harness uses copied native
runtime artifacts and bubblewrap isolation with loopback-only fixtures and synthetic configuration.

Never load real environment/data/credentials, use the installed runtime or `~/ircds`, contact an active
network, or print secrets, hashes, tokens or raw sensitive wire values. Confirm source preservation and
clean up disposable processes/resources. Documentation-only work does not authorize running this harness.

Require actual inbound and outbound evidence: six-block data, correlated durable ACKs, native and Ares
READY, service effects, ordered tail replay, imported-provenance reload, same-process reconnect and
fresh-process epoch/reconciliation. A historical outbound-only run does not prove inbound takeover.
Report commands, observed results and unavailable checks separately; success flags without assertions
on actual native responses/data are insufficient.

Direct protocol-harness proof is not full daemon startup/IRC-connect proof. SQLite, SQL parameter-type
and binary-resource tests are not actual PostgreSQL integration proof; state the tested engine and any
unavailable engine explicitly. Do not discover ambient database sessions or credentials to fill that gap.

## 9. Refactor test rule

Preserve behavior assertions.

Do not replace meaningful tests with tests of the new internal file/class shape.
Use architecture tests for structure and behavior tests for semantics.
