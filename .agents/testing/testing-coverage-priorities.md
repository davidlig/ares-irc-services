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

## Summary

- **Suite:** 680 tests, 1477 assertions.
- **Coverage:** Classes 29.20% (73/250), Methods 36.55% (511/1398), Lines 42.07% (2696/6408).
- Covered classes are those listed in section 1 and in the coverage report (`--coverage-text`).

The prioritisation below is based on code structure and which parts already have associated tests.

---

## 1. Covered (with dedicated tests)

| Layer | Areas |
|-------|--------|
| **Domain** | Entities (RegisteredNick, RegisteredChannel, ChannelAccess, ChannelLevel, Memo, MemoSettings, MemoIgnore), VOs (NickStatus, Uid, Nick, ChannelName, etc.), IRC events/value (Channel, NetworkUser, IRCMessage, …), exceptions (NickServ, ChanServ, MemoServ). |
| **Application** | Ports (SenderView, ChannelView), Commands and handlers (NickServ, ChanServ, MemoServ), services (NickServService, ChanServService, MemoServService), registries (PendingVerification, RecoveryToken, RegisterThrottle, MemoSendThrottle, FounderChangeToken, ChannelRegisterThrottle), helpers (ChanServAccessHelper, EmailMasker, SecureToken, VhostValidator, TimezoneHelpProvider), Mail (SendEmail, SendEmailHandler), Maintenance (RunMaintenanceCycle, Scheduler), ConnectToServerCommand, UnifiedHelpFormatter, command registries. |
| **Infrastructure** | UserMessageTypeResolver, NullChannelModeSupport, UnrealIRCdChannelModeSupport, InspIRCdChannelModeSupport, AbstractProtocolHandler, UnrealIRCdProtocolHandler, InspIRCdProtocolHandler, UnrealIRCdProtocolServiceActions, InspIRCdProtocolServiceActions, UnrealIRCdServiceIntroductionFormatter, InspIRCdServiceIntroductionFormatter, UnrealIRCdVhostCommandBuilder, InspIRCdVhostCommandBuilder, ChannelRankSyncPendingRegistry, ChannelSyncCompletedRegistry, PendingNickRestoreRegistry, SensitiveDataRedactor, Argon2PasswordHasher, InMemoryNetworkUserRepository, InMemoryChannelRepository, RegisteredNickDoctrineRepository (integration), RegisteredChannelDoctrineRepository (integration), ChannelAccessDoctrineRepository (integration), MemoDoctrineRepository (integration), MemoSettingsDoctrineRepository (integration), MemoIgnoreDoctrineRepository (integration), ChannelLevelDoctrineRepository (integration), NickServIdentifiedOwnerVoter, OperVoter, DoctrineIdentityMapClearSubscriber, ChanServEntryMsgSubscriber, MemoServNickIdentifiedNoticeSubscriber, MemoServNickDropCleanupSubscriber, VhostClearOnDeidentifySubscriber. |
| **Application** | BurstCompleteRegistry. |
| **UI** | ConnectCommand. |
| **Domain** | (unchanged) |
| **Domain** | (unchanged) |

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
| **Protocol: formatting and parsing** | `UnrealIRCdProtocolHandler`, `InspIRCdProtocolHandler` | ✅ Covered: `UnrealIRCdProtocolHandlerTest`, `InspIRCdProtocolHandlerTest`. |
| **Protocol: service actions** | `UnrealIRCdProtocolServiceActions`, `InspIRCdProtocolServiceActions` (MODE/KILL/…) | ✅ Covered: `UnrealIRCdProtocolServiceActionsTest`, `InspIRCdProtocolServiceActionsTest`. |
| **Protocol: introduction and vhost** | `*ServiceIntroductionFormatter`, `*VhostCommandBuilder` | ✅ Covered: `*ServiceIntroductionFormatterTest`, `*VhostCommandBuilderTest`. |
| **Helpers / resolvers** | `UserLanguageResolver`, `SensitiveDataRedactor`, `EmailDelayMiddleware` | ✅ Covered: `SensitiveDataRedactorTest`, `UserLanguageResolverTest`, `EmailDelayMiddlewareTest`. |
| **In-memory registries** | `ChannelSyncCompletedRegistry`, `ChannelRankSyncPendingRegistry`, `PendingNickRestoreRegistry`, `BurstCompleteRegistry` | ✅ Covered: all have dedicated tests. |
| **In-memory repositories** | `InMemoryNetworkUserRepository`, `InMemoryChannelRepository` | ✅ Covered: both have dedicated tests. |
| **Security** | `Argon2PasswordHasher` | ✅ Covered: `Argon2PasswordHasherTest`. |
| **Doctrine repositories** | `RegisteredNickDoctrineRepository`, `RegisteredChannelDoctrineRepository`, `ChannelAccessDoctrineRepository`, `MemoDoctrineRepository`, `MemoSettingsDoctrineRepository`, `MemoIgnoreDoctrineRepository`, `ChannelLevelDoctrineRepository` | ✅ Covered: `*DoctrineRepositoryTest` (integration with SQLite). |

### Lower priority (integration or more coupled)

| Area | Examples | Reason |
|------|----------|--------|
| **Subscriber tests** | `ChanServEntryMsgSubscriber`, `MemoServNickIdentifiedNoticeSubscriber`, `MemoServNickDropCleanupSubscriber`, `VhostClearOnDeidentifySubscriber` | ✅ Covered: subscriber tests with mocked dependencies. |
| **Security voters** | `NickServIdentifiedOwnerVoter`, `OperVoter` | ✅ Covered: `NickServIdentifiedOwnerVoterTest`, `OperVoterTest`. |
| **Remaining repositories** | `ChannelLevelDoctrineRepository` (if needed for edge cases) | Similar pattern to existing integration tests. |
| **Bots** | `NickServBot`, `ChanServBot`, `MemoServBot` | Depend on gateway, events and ports; integration or heavily mocked unit tests. |
| **Remaining Subscribers** | `NickProtectionSubscriber`, `MemoServPendingChannelNoticeSubscriber` (depends on final `ChanServAccessHelper`), etc. | Some depend on final classes; integration tests or refactor to interfaces needed. |
| **Connection and network** | `SocketConnection`, `SocketConnectionFactory`, `ActiveConnectionHolder`, network adapters | Depend on socket or network state; integration tests or deep mocks. |
| **SymfonyAuthorizationCheckerAdapter** | Depends on Symfony security component. |

---

## 3. Recommended order for adding tests

1. ~~**ConnectCommand (UI/CLI)**~~ — Done.
2. ~~**ConnectToServerHandler**~~ — Done.
3. ~~**Protocol: parsing and formatting**~~ — Done: `UnrealIRCdProtocolHandlerTest`, `InspIRCdProtocolHandlerTest`.
4. ~~**Protocol: service actions / introduction / vhost**~~ — Done.
5. ~~**In-memory registries** (Infrastructure)~~ — Done.
6. ~~**Helpers / resolvers / security**~~ — Done: `SensitiveDataRedactorTest`, `UserLanguageResolverTest`, `EmailDelayMiddlewareTest`, `Argon2PasswordHasherTest`.
7. ~~**In-memory repositories**~~ — Done: `InMemoryNetworkUserRepositoryTest`, `InMemoryChannelRepositoryTest`.
8. ~~**Doctrine repositories**~~ — Done: `RegisteredNickDoctrineRepositoryTest`, `RegisteredChannelDoctrineRepositoryTest`, `ChannelAccessDoctrineRepositoryTest`, `MemoDoctrineRepositoryTest`, `MemoSettingsDoctrineRepositoryTest`, `MemoIgnoreDoctrineRepositoryTest`, `ChannelLevelDoctrineRepositoryTest` (integration with SQLite).
9. ~~**Security voters**~~ — Done: `NickServIdentifiedOwnerVoterTest`, `OperVoterTest`.
10. ~~**Basic subscribers**~~ — Done: `ChanServEntryMsgSubscriberTest`, `MemoServNickIdentifiedNoticeSubscriberTest`, `MemoServNickDropCleanupSubscriberTest`, `VhostClearOnDeidentifySubscriberTest`.
11. **Additional Doctrine repositories** — set up SQLite in memory for each repository as needed.
12. **Complex subscribers** — Some depend on final classes (e.g., `ChanServAccessHelper`); need integration tests or refactor to interfaces.
13. **Bots** — as needed.

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

When you run coverage and want to keep this document in sync:

- Update the **Summary** with the current suite size (tests, assertions) and coverage percentages (Classes, Methods, Lines) from `--coverage-text`.
- Replace or adjust the “Uncovered” tables with the classes/files that show 0% or uncovered in the HTML report.
- Adjust priorities by uncovered lines and risk (e.g. more weight on security and persistence).
- Update the “Covered” section when adding `#[CoversClass]` to existing tests or creating tests for new classes.
