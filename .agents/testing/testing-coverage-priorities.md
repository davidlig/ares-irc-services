# Testing: coverage and priorities

## How to generate the coverage report

The PHPUnit coverage report requires a **coverage driver** (PCOV or Xdebug).

```bash
# With PCOV (recommended, faster)
php -m | grep pcov   # check it is installed
composer require --dev phpunit/phpunit  # already in project
./vendor/bin/phpunit --coverage-text --coverage-filter=src

# With Xdebug
php -m | grep xdebug
# Ensure xdebug.mode includes coverage (or XDEBUG_MODE=coverage)
./vendor/bin/phpunit --coverage-text --coverage-filter=src
```

If no driver is installed, PHPUnit will not run tests when coverage is requested. To install PCOV:

```bash
# Linux (e.g. Debian/Ubuntu)
sudo apt install php-pcov
# or pecl install pcov
```

Reports are generated in `var/coverage/` (HTML and Clover) as per `phpunit.dist.xml`.

---

## Summary (without driver)

- **src:** ~302 PHP files.
- **tests:** 107 `*Test.php` files.
- **Classes covered explicitly** (via `#[CoversClass(...)]`): ~100.

The prioritisation below is based on code structure and which parts already have associated tests.

---

## 1. Covered (with dedicated tests)

| Layer | Areas |
|-------|--------|
| **Domain** | Entities (RegisteredNick, RegisteredChannel, ChannelAccess, ChannelLevel, Memo, MemoSettings, MemoIgnore), VOs (NickStatus, Uid, Nick, ChannelName, etc.), IRC events/value (Channel, NetworkUser, IRCMessage, …), exceptions (NickServ, ChanServ, MemoServ). |
| **Application** | Ports (SenderView, ChannelView), Commands and handlers (NickServ, ChanServ, MemoServ), services (NickServService, ChanServService, MemoServService), registries (PendingVerification, RecoveryToken, RegisterThrottle, MemoSendThrottle, FounderChangeToken, ChannelRegisterThrottle), helpers (ChanServAccessHelper, EmailMasker, SecureToken, VhostValidator, TimezoneHelpProvider), Mail (SendEmail, SendEmailHandler), Maintenance (RunMaintenanceCycle, Scheduler), ConnectToServerCommand, UnifiedHelpFormatter, command registries. |
| **Infrastructure** | UserMessageTypeResolver, NullChannelModeSupport, UnrealIRCdChannelModeSupport, InspIRCdChannelModeSupport. |

---

## 2. Uncovered (suggested priority)

### High priority (impact and ease)

| Area | Location | Status |
|------|----------|--------|
| **UI/CLI** | `UI/CLI/ConnectCommand.php` | ✅ Covered: `tests/UI/CLI/ConnectCommandTest.php`. |
| **Application** | `Application/IRC/Connect/ConnectToServerHandler.php` | ✅ Covered: `tests/Application/IRC/Connect/ConnectToServerHandlerTest.php`. |
| **Application** | `Application/IRC/IRCClient.php` | Pending: connection loop; tests with connection mocks. |

### Medium priority (Infrastructure unit-testable without DB/socket)

| Area | Examples | Reason |
|------|----------|--------|
| **Protocol: formatting and parsing** | `UnrealIRCdProtocolHandler`, `InspIRCdProtocolHandler` (`parseRawLine`, `formatMessage`) | Pure logic or injectable dependencies; testable with line input/output. |
| **Protocol: service** | `UnrealIRCdProtocolServiceActions`, `InspIRCdProtocolServiceActions` (MODE/KILL/… construction) | String construction per interface; testable with parameter stubs. |
| **Protocol: introduction and vhost** | `*ServiceIntroductionFormatter`, `*VhostCommandBuilder` | Text input/output; easy to test. |
| **Helpers / resolvers** | `UserLanguageResolver`, `SensitiveDataRedactor`, `EmailDelayMiddleware` | Little or no IO dependency; good candidates for unit tests. |
| **In-memory registries** | `ChannelSyncCompletedRegistry`, `ChannelRankSyncPendingRegistry`, `PendingNickRestoreRegistry`, `BurstCompleteRegistry` | Bounded behaviour; similar to Application registries already tested. |

### Lower priority (integration or more coupled)

| Area | Examples | Reason |
|------|----------|--------|
| **Doctrine repositories** | `*DoctrineRepository` in NickServ, ChanServ, MemoServ | Require DB (e.g. SQLite in memory) and possibly fixtures; integration tests. |
| **Bots** | `NickServBot`, `ChanServBot`, `MemoServBot` | Depend on gateway, events and ports; integration or heavily mocked unit tests. |
| **Subscribers** | ChanServ, NickServ, MemoServ, IRC (Network, Logging, etc.) | React to events; unit tests with EventDispatcher and sample events, or integration. |
| **Connection and network** | `SocketConnection`, `SocketConnectionFactory`, `ActiveConnectionHolder`, network adapters | Depend on socket or network state; integration tests or deep mocks. |
| **Security** | `Argon2PasswordHasher`, `NickServIdentifiedOwnerVoter`, `OperVoter`, `SymfonyAuthorizationCheckerAdapter` | Some testable with mocks (voters), others with Symfony/security dependencies. |

---

## 3. Recommended order for adding tests

1. ~~**ConnectCommand (UI/CLI)**~~ — Done.
2. ~~**ConnectToServerHandler**~~ — Done.
3. **Protocol: parsing and formatting** — Unreal and/or InspIRCd (e.g. `parseRawLine` and `formatMessage` with sample lines).
4. **Protocol: service actions / introduction / vhost** — one module (Unreal or InspIRCd) end-to-end for that module.
5. **In-memory registries** (Infrastructure) — one or two (e.g. `ChannelSyncCompletedRegistry`, `PendingNickRestoreRegistry`).
6. **Doctrine repositories** — set up SQLite in memory and one integration test per critical repository.
7. **Subscribers and bots** — as needed, starting with those with more business logic.

---

## 4. Useful commands

```bash
# All tests (no coverage)
./vendor/bin/phpunit --no-coverage

# Domain only
./vendor/bin/phpunit tests/Domain --no-coverage

# Application only
./vendor/bin/phpunit tests/Application --no-coverage

# Infrastructure only
./vendor/bin/phpunit tests/Infrastructure --no-coverage

# With coverage (when driver is available)
./vendor/bin/phpunit --coverage-text --coverage-filter=src
./vendor/bin/phpunit --coverage-html var/coverage/html --coverage-filter=src
```

---

## 5. Maintaining this document

When PCOV/Xdebug is installed and the real report is generated:

- Replace the “Uncovered” table with the classes/files that show 0% or uncovered in `--coverage-text` or the HTML report.
- Adjust priorities by uncovered lines and risk (e.g. more weight on security and persistence).
- Update the “Covered” section when adding `#[CoversClass]` to existing tests or creating tests for new classes.
