# Adding a New IRCd Protocol Module

Checklist for adding support for a new IRCd (e.g., P10, ircu, Bahamut, Charybdis, Ergo, Solanum).

## 1. Research & Documentation

- [ ] Obtain official protocol documentation for the target IRCd version
- [ ] Document wire format: message structure, prefixes, tokens
- [ ] Document server-server link handshake sequence
- [ ] Document mode letters and prefixes (channel, user)
- [ ] Document service introduction format (how pseudo-clients appear)
- [ ] Document service commands: SVSNICK, SVSMODE, CHGHOST, KILL, etc.
- [ ] Document vhost/cloaking support if applicable

Store documentation in `docs/<ircdname>/` for reference.

## 2. Create Namespace Structure

```
src/Infrastructure/IRC/Protocol/<Name>/
├── <Name>Module.php                    (implements ProtocolRuntimeModuleInterface)
├── <Name>ProtocolHandler.php           (implements ProtocolHandlerInterface)
├── <Name>ProtocolServiceActions.php    (implements ProtocolServiceActionsInterface)
├── <Name>ServiceIntroductionFormatter.php
├── <Name>ChannelModeSupport.php
├── <Name>UserModeSupport.php
└── <Name>NickReservation.php

src/Infrastructure/IRC/Network/Adapter/
└── <Name>NetworkStateAdapter.php       (implements NetworkStateAdapterInterface)
```

## 3. Implement ProtocolRuntimeModuleInterface

Create the module class that bundles all components:

```php
final readonly class <Name>Module implements ProtocolRuntimeModuleInterface
{
    public function __construct(
        private <Name>ProtocolHandler $handler,
        private <Name>ProtocolServiceActions $serviceActions,
        private <Name>ServiceIntroductionFormatter $introductionFormatter,
        private <Name>ChannelModeSupport $channelModeSupport,
        private <Name>UserModeSupport $userModeSupport,
        private <Name>NickReservation $nickReservation,
    ) {}

    public function getProtocolName(): string { return '<name>'; }
    public function getHandler(): ProtocolHandlerInterface { return $this->handler; }
    public function getServiceActions(): ProtocolServiceActionsInterface { return $this->serviceActions; }
    public function getIntroductionFormatter(): ServiceIntroductionFormatterInterface { return $this->introductionFormatter; }
    public function getChannelModeSupport(): ChannelModeSupportInterface { return $this->channelModeSupport; }
    public function getUserModeSupport(): UserModeSupportInterface { return $this->userModeSupport; }
    public function getNickReservation(): ?ServiceNickReservationInterface { return $this->nickReservation; }
}
```

`ProtocolModuleInterface` intentionally excludes `getHandler()`. Infrastructure runtime code uses `ProtocolRuntimeModuleInterface` for that method. The network-state adapter is registered separately and is not exposed by either module interface.

## 4. Protocol Handler

Implement the complete `ProtocolHandlerInterface`: `performHandshake()`, `handleIncoming()`, `parseRawLine()`, `formatMessage()`, `getProtocolName()`, and `getSupportedCapabilities()`.

```php
public function parseRawLine(string $rawLine): IRCMessage
{
    // Parse wire format into canonical IRCMessage
    // Handle protocol-specific quirks (P10 tokens, special prefixes, etc.)
}

public function formatMessage(IRCMessage $message): string
{
    // Convert canonical IRCMessage to wire format
}
```

Extending `AbstractProtocolHandler` is optional; use it only when its shared behavior matches the IRCd.

## 5. Network State Adapter

Convert wire messages to domain events:

```php
public function getSupportedProtocol(): string
{
    return '<name>';
}

public function handleMessage(IRCMessage $message): void
{
    // Dispatch the appropriate domain/infrastructure events.
}
```

## 6. Protocol Service Actions

Implement all methods of `ProtocolServiceActionsInterface` to execute network-level actions:

- `introduceService(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname, string $serviceKey = ''): void`
- `setUserVhost(string $serverSid, string $targetUid, string $vhost, string $cloakedHost = ''): void`
- `setUserAccount(string $serverSid, string $targetUid, string $accountName): void`
- `setUserMode(string $serverSid, string $targetUid, string $modes, array $params = []): void`
- `forceNick(string $serverSid, string $targetUid, string $newNick): void`
- `killUser(string $serverSid, string $targetUid, string $reason): void`
- `setChannelModes(string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = '', ?int $channelTimestamp = null): void`
- `setChannelMemberMode(string $serverSid, string $channelName, string $targetUid, string $modeLetter, bool $add, string $serviceUid = '', ?int $channelTimestamp = null): void`
- `inviteUserToChannel(string $serverSid, string $channelName, string $targetUid, string $serviceUid = '', ?int $channelTimestamp = null): void`
- `joinChannelAsService(string $serverSid, string $channelName, string $serviceUid, string $maxPrefixLetter, ?int $channelTimestamp = null): void`
- `partChannelAsService(string $serverSid, string $channelName, string $serviceUid): void`
- `setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $serviceUid = '', ?int $channelCreationTs = null): void`
- `kickFromChannel(string $serverSid, string $channelName, string $targetUid, string $reason, string $serviceUid = ''): void`
- `addGline(...)`, `removeGline(...)`, `introducePseudoClient(...)`, `quitPseudoClient(...)`

## 7. Channel Mode Support

```php
public function hasVoice(): bool;
public function hasHalfOp(): bool;
public function hasOp(): bool;
public function hasAdmin(): bool;
public function hasOwner(): bool;
public function getSupportedPrefixModes(): array;
public function getListModeLetters(): array;
public function getChannelSettingModesUnsetWithoutParam(): array;
public function getChannelSettingModesUnsetWithParam(): array;
public function getChannelSettingModesWithParamOnSet(): array;
public function hasChannelRegisteredMode(): bool;
public function getChannelRegisteredModeLetter(): ?string;
public function hasPermanentChannelMode(): bool;
public function getPermanentChannelModeLetter(): ?string;
```

## 8. DI Configuration

Register in `config/services.yaml`:

```yaml
# --- <Name> Protocol Module ----------------------------------------

App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolHandler:
    # dependencies...

App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolServiceActions:
    arguments:
        $connectionHolder: '@App\Infrastructure\IRC\Connection\ActiveConnectionHolder'

App\Infrastructure\IRC\Protocol\<Name>\<Name>Module:
    arguments:
        $handler: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolHandler'
        $serviceActions: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolServiceActions'
        $introductionFormatter: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ServiceIntroductionFormatter'
        $channelModeSupport: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ChannelModeSupport'
        $userModeSupport: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>UserModeSupport'
        $nickReservation: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>NickReservation'
    tags:
        - { name: irc.protocol_module }

# Network state adapter routing
App\Infrastructure\IRC\Protocol\ProtocolNetworkStateRouter:
    arguments:
        $adapters:
            unreal: '@App\Infrastructure\IRC\Network\Adapter\UnrealIRCdNetworkStateAdapter'
            unrealudb: '@App\Infrastructure\IRC\Network\Adapter\UnrealUdbNetworkStateAdapter'
            inspircd: '@App\Infrastructure\IRC\Network\Adapter\InspIRCdNetworkStateAdapter'
            <name>: '@App\Infrastructure\IRC\Network\Adapter\<Name>NetworkStateAdapter'
```

## 9. Tests (100% Coverage Mandatory)

- [ ] Unit tests for `parseRawLine()` with various wire formats
- [ ] Unit tests for `formatMessage()` with various `IRCMessage`s
- [ ] Unit tests for `<Name>ProtocolServiceActions` (introduceService, setUserVhost, setUserAccount, forceNick, setChannelTopic, kickFromChannel, etc.)
- [ ] Unit tests for `<Name>NetworkStateAdapter`
- [ ] Unit tests for `<Name>ChannelModeSupport`
- [ ] Unit tests for `<Name>Module`

## 10. Configuration

Add to `.env` and config:

```yaml
# config/services.yaml parameters
parameters:
    irc.protocol: '%env(IRC_PROTOCOL)%'  # 'unreal', 'unrealudb', 'inspircd', '<name>'
```

## 11. Verify

- [ ] No `match`/`switch` over protocol name in shared code
- [ ] Registry automatically discovers module via tag `irc.protocol_module`
- [ ] All protocol-specific wire code stays in `<Name>/` namespace
- [ ] `docs/<name>/` contains protocol documentation
- [ ] New/modified test files pass a focused `./vendor/bin/phpunit --no-coverage --display-all-issues Test1.php Test2.php ...` run during development
- [ ] After the complete implementation, the pre-commit chain passes: `lint:container`, `lint:yaml`, `phpstan` (zero errors), `php-cs-fixer`, `./scripts/check-coverage.sh 100 --issues` (the only full-suite run)

## Reference Implementations

| IRCd | Module | Handler | Service Actions |
|------|--------|---------|-----------------|
| UnrealIRCd 6 | `Unreal\UnrealIRCdModule` | `UnrealIRCdProtocolHandler` | `UnrealIRCdProtocolServiceActions` |
| UnrealIRCd-UDB 6 | `UnrealUdb\UnrealUdbModule` | `UnrealUdbProtocolHandler` | `UnrealUdbProtocolServiceActions` |
| InspIRCd 4 | `InspIRCd\InspIRCdModule` | `InspIRCdProtocolHandler` | `InspIRCdProtocolServiceActions` |
