# Adding a New IRCd Protocol Module

Checklist for adding support for a new IRCd (e.g., P10, ircu, Bahamut).

## 1. Research & Documentation

- [ ] Obtain official protocol documentation for the IRCd version
- [ ] Document wire format: message structure, prefixes, tokens
- [ ] Document server-server link handshake sequence
- [ ] Document mode letters and prefixes (channel, user)
- [ ] Document service introduction format (how pseudo-clients appear)
- [ ] Document service commands: SVSNICK, SVSMODE, KILL, etc.
- [ ] Document vhost/mod support if applicable

Store documentation in `docs/<ircdname>/` for reference.

## 2. Create Namespace Structure

```
src/Infrastructure/IRC/Protocol/<Name>/
├── <Name>Module.php                    (implements ProtocolModuleInterface)
├── <Name>ProtocolHandler.php           (extends AbstractProtocolHandler)
├── <Name>NetworkStateAdapter.php
├── <Name>ServiceIntroductionFormatter.php
├── <Name>ProtocolServiceActions.php
├── <Name>ChannelModeSupport.php        (implements ChannelModeSupportInterface)
└── <Name>VhostCommandBuilder.php       (if supported, use NullVhostCommandBuilder otherwise)
```

## 3. Implement ProtocolModuleInterface

Create the module class that bundles all components:

```php
final readonly class <Name>Module implements ProtocolModuleInterface
{
    public function __construct(
        private <Name>ProtocolHandler $handler,
        private <Name>NetworkStateAdapter $adapter,
        private <Name>ServiceIntroductionFormatter $introductionFormatter,
        private <Name>ProtocolServiceActions $serviceActions,
        private <Name>ChannelModeSupport $modeSupport,
        private ?<Name>VhostCommandBuilder $vhostBuilder = null,
    ) {}

    public function getProtocolName(): string { return '<name>'; }
    public function getHandler(): ProtocolHandler { return $this->handler; }
    public function getNetworkStateAdapter(): NetworkStateAdapter { return $this->adapter; }
    public function getIntroductionFormatter(): ServiceIntroductionFormatterInterface { return $this->introductionFormatter; }
    public function getServiceActions(): ProtocolServiceActions { return $this->serviceActions; }
    public function getChannelModeSupport(): ChannelModeSupportInterface { return $this->modeSupport; }
    public function getVhostCommandBuilder(): ?VhostCommandBuilderInterface { return $this->vhostBuilder; }
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

## 6. Service Introduction Formatter

Format the introduction line(s) for pseudo-clients:

```php
public function formatIntroduction(
    string $serverSid,
    string $nick,
    string $ident,
    string $hostname,
    string $uid,
    string $realname,
): string;
```

## 7. Protocol Service Actions

Implement IRCd-specific commands:

- `setUserAccount()`: Set/unset account name (e.g., +r)
- `setUserMode()`: Set user modes
- `forceNick()`: Force nickname change (SVSNICK equivalent)
- `killUser()`: Kill a user
- `setChannelModes()`: Set channel modes
- `setChannelMemberMode()`: Set prefix modes (op, voice, etc.)
- `joinChannelAsService()`: Join channel as service bot
- `setChannelTopic()`: Set channel topic

## 8. Channel Mode Support

```php
public function getSupportedPrefixModes(): array; // ['q', 'a', 'o', 'h', 'v'] etc.
public function getPrefixForLevel(int $level): string;
public function parseModeString(string $modeStr, array $params): ModeChangeCollection;
```

## 9. Vhost Command Builder (if supported)

```php
public function getSetVhostLine(string $sid, string $uid, string $vhost): string;
public function getClearVhostLine(string $sid, string $uid): string;
```

If not supported, use `NullVhostCommandBuilder` or return `null` from module.

## 10. DI Configuration

Register in `config/services.yaml`:

```yaml
# --- <Name> Protocol Module ----------------------------------------

App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolHandler:
    # dependencies...

App\Infrastructure\IRC\Protocol\<Name>\<Name>Module:
    arguments:
        $handler: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>ProtocolHandler'
        # ... other dependencies
    tags:
        - { name: irc.protocol_module }

# Network state adapter routing
App\Infrastructure\IRC\Protocol\ProtocolNetworkStateRouter:
    arguments:
        $adapters:
            unreal: '@App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdNetworkStateAdapter'
            inspircd: '@App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdNetworkStateAdapter'
            <name>: '@App\Infrastructure\IRC\Protocol\<Name>\<Name>NetworkStateAdapter'
```

## 11. Tests

- [ ] Unit tests for `parseRawLine()` with various wire formats
- [ ] Unit tests for `formatMessage()` with various IRCMessages
- [ ] Unit tests for service actions (setUserAccount, forceNick, etc.)
- [ ] Unit tests for introduction formatter
- [ ] Unit tests for channel mode support

## 12. Configuration

Add to `.env` and config:

```yaml
# config/services.yaml parameters
parameters:
    irc.protocol: '%env(IRC_PROTOCOL)%'  # 'unreal', 'inspircd', '<name>'
```

## 13. Verify

- [ ] No `match`/`switch` over protocol name in shared code
- [ ] Registry automatically discovers module via tag
- [ ] All protocol-specific code stays in `<Name>/` namespace
- [ ] `docs/<name>/` contains protocol documentation

## Reference Implementations

| IRCd | Module | Handler | Actions |
|------|--------|---------|---------|
| UnrealIRCd 6 | `Unreal\UnrealIRCdModule` | `UnrealIRCdProtocolHandler` | `UnrealIRCdProtocolServiceActions` |
| InspIRCd 4 | `InspIRCd\InspIRCdModule` | `InspIRCdProtocolHandler` | `InspIRCdProtocolServiceActions` |