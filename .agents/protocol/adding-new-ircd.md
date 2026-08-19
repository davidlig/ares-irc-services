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
├── <Name>Module.php                    (implements ProtocolModuleInterface)
├── <Name>ProtocolHandler.php           (implements ProtocolHandlerInterface / extends AbstractProtocolHandler)
├── <Name>NetworkStateAdapter.php       (implements NetworkStateAdapterInterface)
├── <Name>ProtocolServiceActions.php    (implements ProtocolServiceActionsInterface)
└── <Name>ChannelModeSupport.php        (implements ChannelModeSupportInterface)
```

## 3. Implement ProtocolModuleInterface

Create the module class that bundles all components:

```php
final readonly class <Name>Module implements ProtocolModuleInterface
{
    public function __construct(
        private <Name>ProtocolHandler $handler,
        private <Name>NetworkStateAdapter $adapter,
        private <Name>ProtocolServiceActions $serviceActions,
        private <Name>ChannelModeSupport $modeSupport,
    ) {}

    public function getProtocolName(): string { return '<name>'; }
    public function getHandler(): ProtocolHandlerInterface { return $this->handler; }
    public function getNetworkStateAdapter(): NetworkStateAdapterInterface { return $this->adapter; }
    public function getServiceActions(): ProtocolServiceActionsInterface { return $this->serviceActions; }
    public function getChannelModeSupport(): ChannelModeSupportInterface { return $this->modeSupport; }
}
```

## 4. Protocol Handler

Implement `parseRawLine()` and `formatMessage()`:

```php
protected function parseRawLine(string $rawLine): ?IRCMessage
{
    // Parse wire format into canonical IRCMessage
    // Handle protocol-specific quirks (P10 tokens, special prefixes, etc.)
}

public function formatMessage(IRCMessage $message): string
{
    // Convert canonical IRCMessage to wire format
}
```

Reference: `AbstractProtocolHandler` for handshake and common parsing.

## 5. Network State Adapter

Convert wire messages to domain events:

```php
public function adapt(IRCMessage $message, string $direction): array // DomainEvent[]
{
    // Return appropriate domain events:
    // - UserConnectedEvent, UserQuitEvent, UserNickChangeEvent
    // - ChannelJoinEvent, ChannelPartEvent, ChannelModeChangeEvent
    // - ServerConnectedEvent, ServerDelinkedEvent
    // etc.
}
```

## 6. Protocol Service Actions

Implement all methods of `ProtocolServiceActionsInterface` to execute network-level actions:

- `introduceService(string $serverSid, string $nick, string $ident, string $host, string $uid, string $realname, string $serviceKey): void`
- `setUserVhost(string $serverSid, string $targetUid, ?string $vhost, string $cloakedHost = ''): void`
- `setUserAccount(string $serverSid, string $targetUid, ?string $account): void`
- `setUserMode(string $serverSid, string $targetUid, string $modes): void`
- `forceNick(string $serverSid, string $targetUid, string $newNick, int $ts): void`
- `killUser(string $serverSid, string $targetUid, string $reason): void`
- `setChannelModes(string $serverSid, string $channelName, string $modeString, ?int $creationTs = null): void`
- `setChannelMemberMode(string $serverSid, string $channelName, string $mode, string $targetUid): void`
- `joinChannelAsService(string $serverSid, string $channelName, string $serviceUid): void`
- `partChannelAsService(string $serverSid, string $channelName, string $serviceUid): void`
- `setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $setterUid, ?int $creationTs = null): void`
- `kickFromChannel(string $serverSid, string $channelName, string $targetUid, string $reason, string $kickerUid): void`

## 7. Channel Mode Support

```php
public function getSupportedPrefixModes(): array; // ['q', 'a', 'o', 'h', 'v'] etc.
public function getPrefixForLevel(int $level): string;
public function parseModeString(string $modeStr, array $params): ModeChangeCollection;
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
        $adapter: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>NetworkStateAdapter'
        $serviceActions: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolServiceActions'
        $modeSupport: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ChannelModeSupport'
    tags:
        - { name: irc.protocol_module }

# Network state adapter routing
App\Infrastructure\IRC\Protocol\ProtocolNetworkStateRouter:
    arguments:
        $adapters:
            unreal: '@App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdNetworkStateAdapter'
            unrealudb: '@App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter'
            inspircd: '@App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdNetworkStateAdapter'
            <name>: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>NetworkStateAdapter'
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
- [ ] Pre-commit chain passes: `lint:container`, `lint:yaml`, `php-cs-fixer`, `phpunit`, `check-coverage 100`

## Reference Implementations

| IRCd | Module | Handler | Service Actions |
|------|--------|---------|-----------------|
| UnrealIRCd 6 | `Unreal\UnrealIRCdModule` | `UnrealIRCdProtocolHandler` | `UnrealIRCdProtocolServiceActions` |
| UnrealIRCd-UDB 6 | `UnrealUdb\UnrealUdbModule` | `UnrealUdbProtocolHandler` | `UnrealUdbProtocolServiceActions` |
| InspIRCd 4 | `InspIRCd\InspIRCdModule` | `InspIRCdProtocolHandler` | `InspIRCdProtocolServiceActions` |