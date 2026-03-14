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

- **Suite:** 1494 tests, 4004 assertions.
- **Coverage (with PCOV):** Generate with `./vendor/bin/phpunit --coverage-text --coverage-filter=src`. Run with `--display-deprecations --display-phpunit-deprecations` to ensure 0 deprecations/notices.
- **Excluded from coverage:** `src/Kernel.php` (Symfony bootstrap).
- **PHPUnit Notices:** FIXED (2026-03-14). All tests pass with 0 notices, 0 deprecations.
- **Coverage by Layer (2026-03-14):** Domain 99.4%, Application 80.1% (was 68.4%), Infrastructure 84.5% (was 83.1%), UI 94.9%.
- Newly covered (2026-03-14): 
  - **ChanServ:** SetCommand 86% (was 30%), InfoCommand 98% (was 31%), HalfopCommand 100% (was 41%), VoiceCommand 100% (was 42%), OpCommand 100% (was 54%), SetFounderHandler 90% (was 58%).
  - **NickServ:** HelpCommand 99% (was 17%), RecoverCommand 95% (was 33%), VerifyCommand 100% (was 50%), IdentifyCommand 94% (was 58%), RegisterCommand 93% (was 68%).
  - **MemoServ:** IgnoreCommand 85% (was 52%), ReadCommand 81% (was 48%), SendCommand 87% (was 63%).
  - **Infrastructure:** NickServBot 96% (was 46%).

The prioritisation below is based on code structure and which parts already have associated tests.

---

## 1. Covered (with dedicated tests)

| Layer | Areas |
|-------|--------|
| **Domain** | Entities (RegisteredNick, RegisteredChannel, ChannelAccess, ChannelLevel, Memo, MemoSettings, MemoIgnore), VOs (NickStatus, Uid, Nick, ChannelName, etc.), IRC events/value (Channel, NetworkUser, IRCMessage, …), exceptions (NickServ, ChanServ, MemoServ), events (ChannelDropEvent, NickIdentifiedEvent). |
| **Application** | Ports (SenderView, ChannelView), Commands and handlers (NickServ, ChanServ, MemoServ), services (NickServService, ChanServService, MemoServService), NickServContext, NickServ HelpFormatterContextAdapter, NickProtectionService, IdentifiedUserVhostSyncService, registries (PendingVerification, RecoveryToken, RegisterThrottle, MemoSendThrottle, FounderChangeToken, ChannelRegisterThrottle, IdentifiedSessionRegistry, IdentifyFailedAttemptRegistry, PendingEmailChangeRegistry), BurstState, helpers (ChanServAccessHelper, EmailMasker, SecureToken, VhostValidator, TimezoneHelpProvider), NickServPermission, Mail (SendEmail, SendEmailHandler), Maintenance (RunMaintenanceCycle, Scheduler, PruneMemoryRegistriesTask, PurgeExpiredPendingTask, PurgeInactiveNicknamesTask, and NickServ Maintenance pruners), ConnectToServerCommand, IRCClient, IRCClientFactory, UnifiedHelpFormatter, command registries. |
| **Infrastructure** | UserMessageTypeResolver, NullChannelModeSupport, UnrealIRCdChannelModeSupport, InspIRCdChannelModeSupport, AbstractProtocolHandler, UnrealIRCdProtocolHandler, InspIRCdProtocolHandler, UnrealIRCdProtocolServiceActions, InspIRCdProtocolServiceActions, UnrealIRCdServiceIntroductionFormatter, InspIRCdServiceIntroductionFormatter, UnrealIRCdVhostCommandBuilder, InspIRCdVhostCommandBuilder, ChannelRankSyncPendingRegistry, ChannelSyncCompletedRegistry, PendingNickRestoreRegistry, SensitiveDataRedactor, Argon2PasswordHasher, InMemoryNetworkUserRepository, InMemoryChannelRepository, RegisteredNickDoctrineRepository (integration), RegisteredChannelDoctrineRepository (integration), ChannelAccessDoctrineRepository (integration), MemoDoctrineRepository (integration), MemoSettingsDoctrineRepository (integration), MemoIgnoreDoctrineRepository (integration), ChannelLevelDoctrineRepository (integration), NickServIdentifiedOwnerVoter, OperVoter, SymfonyAuthorizationCheckerAdapter, DoctrineIdentityMapClearSubscriber, ChanServEntryMsgSubscriber, MemoServNickIdentifiedNoticeSubscriber, MemoServNickDropCleanupSubscriber, VhostClearOnDeidentifySubscriber, ServiceCommandGateway, CoreNetworkUserLookupAdapter, ChanServTopicApplySubscriber, ChanServTopicSyncSubscriber, ChanServRejoinSubscriber, MemoServChannelDropCleanupSubscriber, ChanServMlockEnforceSubscriber, ChanServChannelRankSubscriber, MemoServPendingChannelNoticeSubscriber, NickServBot, ChanServBot, MemoServBot, BurstCompleteRegistrySubscriber, ChannelSyncCompletedMarkerSubscriber, IRCEventSubscriber, ServerDelinkedSubscriber, NetworkStateSubscriber, SyncCompleteDispatcherSubscriber, ActiveConnectionHolder, SocketConnection, SocketConnectionFactory, CoreSendNoticeAdapter, ActiveChannelModeSupportProvider, CoreApplyOutgoingChannelModesAdapter, CoreChannelLookupAdapter, CoreBurstCompleteAdapter, ProtocolHandlerRegistry, ProtocolNetworkStateRouter, NetworkEventEnricher, LocalUserModeSync, UnrealIRCdNetworkStateAdapter, InspIRCdNetworkStateAdapter. |
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
| **Application** | `Application/IRC/IRCClient.php` | ✅ Covered: `tests/Application/IRC/IRCClientTest.php` (connect, run loop with mocks, disconnect, getProtocolName). |

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
| **Subscriber tests** | `ChanServEntryMsgSubscriber`, `MemoServNickIdentifiedNoticeSubscriber`, `MemoServNickDropCleanupSubscriber`, `VhostClearOnDeidentifySubscriber`, `ChanServTopicApplySubscriber`, `ChanServTopicSyncSubscriber`, `ChanServRejoinSubscriber`, `MemoServChannelDropCleanupSubscriber`, `BurstCompleteRegistrySubscriber`, `ChannelSyncCompletedMarkerSubscriber`, `IRCEventSubscriber`, `ServerDelinkedSubscriber`, `NetworkStateSubscriber` | ✅ Covered: subscriber tests. |
| **ServiceBridge tests** | `ServiceCommandGateway`, `CoreNetworkUserLookupAdapter` | ✅ Covered: adapter and gateway tests. |
| **Complex subscribers** | `ChanServMlockEnforceSubscriber`, `ChanServChannelRankSubscriber`, `MemoServPendingChannelNoticeSubscriber` | ✅ Covered: `ChanServMlockEnforceSubscriberTest`, `ChanServChannelRankSubscriberTest`, `MemoServPendingChannelNoticeSubscriberTest` (ChanServAccessHelper real with mocked repos). |
| **Security voters** | `NickServIdentifiedOwnerVoter`, `OperVoter` | ✅ Covered: `NickServIdentifiedOwnerVoterTest`, `OperVoterTest`. |
| **Remaining repositories** | `ChannelLevelDoctrineRepository` (if needed for edge cases) | Similar pattern to existing integration tests. |
| **Bots** | `NickServBot`, `ChanServBot`, `MemoServBot` | ✅ Covered: `NickServBotTest`, `ChanServBotTest`, `MemoServBotTest` (real ActiveConnectionHolder + protocol module mocks). |
| **Connection and network** | `SocketConnection`, `SocketConnectionFactory`, `ActiveConnectionHolder`, network adapters | ✅ Covered: `ActiveConnectionHolderTest`, `SocketConnectionFactoryTest`, `SocketConnectionTest` (unit + in-process TCP server for connect/read/write). IRCClient covered via IRCClientTest. |
| **SymfonyAuthorizationCheckerAdapter** | ✅ Covered: `SymfonyAuthorizationCheckerAdapterTest` (delegation to Symfony checker). |

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
10. ~~**Basic subscribers**~~ — Done: `ChanServEntryMsgSubscriberTest`, `MemoServNickIdentifiedNoticeSubscriberTest`, `MemoServNickDropCleanupSubscriberTest`, `VhostClearOnDeidentifySubscriberTest`, `ChanServTopicApplySubscriberTest`, `ChanServTopicSyncSubscriberTest`, `ChanServRejoinSubscriberTest`, `MemoServChannelDropCleanupSubscriberTest`, `BurstCompleteRegistrySubscriberTest`, `ChannelSyncCompletedMarkerSubscriberTest`, `IRCEventSubscriberTest`, `ServerDelinkedSubscriberTest`, `NetworkStateSubscriberTest`.
11. ~~**ServiceBridge / Adapters**~~ — Done: `ServiceCommandGatewayTest`, `CoreNetworkUserLookupAdapterTest`.
12. ~~**Additional Doctrine repositories**~~ — All seven Doctrine repositories have integration tests. Coverage gaps in `RegisteredNickDoctrineRepository` were closed by adding tests for `findRegisteredInactiveSince` and `deleteExpiredPending`.
13. ~~**Complex subscribers**~~ — Done: `ChanServMlockEnforceSubscriberTest`, `ChanServChannelRankSubscriberTest`, `MemoServPendingChannelNoticeSubscriberTest`.
14. ~~**Bots**~~ — Done: `NickServBotTest`, `ChanServBotTest`, `MemoServBotTest`.

---

## 4. Division by Agents

To run coverage by area in parallel (each Agent runs only its tests and its `src/`):

| Agent | Scope | PHPUnit commands (with coverage) |
|-------|--------|----------------------------------|
| **1 ChanServ** | Application + Infrastructure ChanServ | `tests/Application/ChanServ` + `tests/Infrastructure/ChanServ` with `--coverage-filter=src/Application/ChanServ` and `src/Infrastructure/ChanServ` |
| **2 NickServ** | Application + Infrastructure NickServ | `tests/Application/NickServ` + `tests/Infrastructure/NickServ` with filters to their `src/` |
| **3 MemoServ** | Application + Infrastructure MemoServ | `tests/Application/MemoServ` + `tests/Infrastructure/MemoServ` with filters to their `src/` |
| **4 IRC Core** | Infrastructure IRC (adapters, enricher, sync) | `tests/Infrastructure/IRC` with `--coverage-filter=src/Infrastructure/IRC` |
| **5 Shared/CLI/Mail/Messenger** | UI/CLI, Mail, Messenger, optional Maintenance | `tests/UI/CLI` + `tests/Infrastructure/Mail` + `tests/Infrastructure/Messenger` (+ optional `tests/Application/Maintenance`) with filters to `src/UI`, `src/Infrastructure/Mail`, `src/Infrastructure/Messenger`, `src/Application/Maintenance` |

**Agent 5 — Concrete commands:**

```bash
./vendor/bin/phpunit tests/UI/CLI --coverage-text --coverage-filter=src/UI
./vendor/bin/phpunit tests/Infrastructure/Mail --coverage-text --coverage-filter=src/Infrastructure/Mail
./vendor/bin/phpunit tests/Infrastructure/Messenger --coverage-text --coverage-filter=src/Infrastructure/Messenger
./vendor/bin/phpunit tests/Application/Maintenance --coverage-text --coverage-filter=src/Application/Maintenance
```

Each agent must use `--display-deprecations --display-phpunit-deprecations`. For specific gaps, check `<line count="0">` in `var/coverage/clover.xml` for their files.

### Agent 1 (ChanServ) — Current status

- **Commands (run both for full ChanServ coverage):**
  ```bash
  ./vendor/bin/phpunit tests/Application/ChanServ tests/Infrastructure/ChanServ --coverage-text --coverage-filter=src/Application/ChanServ --coverage-filter=src/Infrastructure/ChanServ --display-deprecations --display-phpunit-deprecations
  ```
  Note: if the coverage driver only applies one filter, run separately:
  `tests/Application/ChanServ` with `--coverage-filter=src/Application/ChanServ` and
  `tests/Infrastructure/ChanServ` with `--coverage-filter=src/Infrastructure/ChanServ`.

- **Key code:** `src/Application/ChanServ/`, `src/Infrastructure/ChanServ/`.

- **Current coverage (ChanServ suite):** Many classes already at 100% (ChanServAccessHelper, ChannelRegisterThrottleRegistry, SetEmailHandler, SetEntrymsgHandler, SetMlockHandler, SetSecureHandler, SetSuccessorHandler, SetTopiclockHandler, PurgeInactiveChannelsTask, HelpFormatterContextAdapter, ChanServCommandListener, etc.). Gaps: ChanServService 50% methods (1/2), ChanServContext 93% methods (14/15), OP/DEOP/VOICE/etc. commands with ~8–25% methods (subcommands uncovered), SetFounderHandler 20% methods, ChanServBot 33% methods, ChanServChannelRankSubscriber and ChanServMlockEnforceSubscriber with uncovered methods.

- **PHPUnit Notices:** RESOLVED. All tests now pass with 0 notices and 0 deprecations. Key fixes applied:
  - `AccessCommandTest.php`: Changed `createMock` to `createStub` in `createChannelMock()` (17 notices fixed).
  - `MemoServBotTest.php`: Changed `createMock` to `createStub` for `ConnectionInterface` (2 notices fixed).
  - `NetworkEventEnricherTest.php`: Changed `createMock` to `createStub` for repository mocks without verification, removed deprecated `expects(self::any())` patterns (5 notices/deprecations fixed).
  - See `.agents/testing/README.md` for detailed guidance on PHPUnit 13 mock/stub patterns.

### Agent 2 (NickServ) — Current status

- **Commands (run both for NickServ coverage):**
  ```bash
  ./vendor/bin/phpunit tests/Application/NickServ tests/Infrastructure/NickServ --coverage-text --coverage-filter=src/Application/NickServ --coverage-filter=src/Infrastructure/NickServ --display-deprecations --display-phpunit-deprecations
  ```
  If the driver only applies one filter, run separately with each `--coverage-filter`.

- **Key code:** `src/Application/NickServ/`, `src/Infrastructure/NickServ/`.

- **Current coverage (NickServ suite):** Context, HelpFormatterContextAdapter, pruners, IdentifiedUserVhostSyncService, NickProtectionService, NickServPermission, PurgeExpiredPendingTask, PurgeInactiveNicknamesTask, PruneMemoryRegistriesTask, NickServCommandListener, NickProtectionSubscriber and most registries/helpers at 100%. Gaps: HelpCommand ~19% lines, other command handlers with uncovered branches, NickServBot ~46% lines, Argon2PasswordHasher 50% methods (only hash/verify on interface; verify covered). RegisteredNickDoctrineRepository is covered by `tests/Integration/Infrastructure/NickServ/Doctrine/RegisteredNickDoctrineRepositoryTest.php` (not included in the path above).

---

## 5. Coverage threshold (optional)

To fail the build if line coverage drops below a minimum:

```bash
./scripts/check-coverage.sh [MIN_PERCENT]
# Example: ./scripts/check-coverage.sh 57   # enforce current baseline
#          ./scripts/check-coverage.sh 100  # enforce 100% (once reached)
```

The script runs PHPUnit with Clover, parses `var/coverage/clover.xml`, and exits with 1 if coverage is below the given percentage.

## 6. Useful commands

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

## 7. Maintaining this document

When you run coverage and want to keep this document in sync:

- Update the **Summary** with the current suite size (tests, assertions) and coverage percentages (Classes, Methods, Lines) from `--coverage-text`.
- Replace or adjust the “Uncovered” tables with the classes/files that show 0% or uncovered in the HTML report.
- Adjust priorities by uncovered lines and risk (e.g. more weight on security and persistence).
- Update the “Covered” section when adding `#[CoversClass]` to existing tests or creating tests for new classes.
