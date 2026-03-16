# Protocol Modules & Multi-IRCd Support

Use this skill when implementing protocol handlers, network state adapters, or adding support for a new IRCd.

## One Module Per IRCd

Each supported IRCd has a **single protocol module** that bundles all protocol-specific implementations:

```
src/Infrastructure/IRC/Protocol/
├── ProtocolModuleInterface.php      (in Application/Port)
├── ProtocolModuleRegistry.php
├── Unreal/
│   ├── UnrealIRCdModule.php         (implements ProtocolModuleInterface)
│   ├── UnrealIRCdProtocolHandler.php
│   ├── UnrealIRCdNetworkStateAdapter.php
│   ├── UnrealIRCdServiceIntroductionFormatter.php
│   ├── UnrealIRCdProtocolServiceActions.php
│   ├── UnrealIRCdChannelModeSupport.php
│   └── UnrealIRCdVhostCommandBuilder.php
├── InspIRCd/
│   ├── InspIRCdModule.php
│   ├── InspIRCdProtocolHandler.php
│   └── ...
└── NullChannelModeSupport.php
```

## No Generic Delegators

**FORBIDDEN**: A class that switches on protocol name:

```php
// WRONG
class ProtocolDelegator {
    public function handle(string $rawLine): IRCMessage {
        return match ($this->protocol) {
            'unreal' => $this->unrealHandler->parse($rawLine),
            'inspircd' => $this->inspircdHandler->parse($rawLine),
        };
    }
}
```

**CORRECT**: Use the registry and obtain the active module:

```php
// Registry builds map from tagged services
$module = $this->connectionHolder->getProtocolModule();
$handler = $module->getHandler();
$message = $handler->parseRawLine($rawLine);
```

Adding a new IRCd = create module class + tag `irc.protocol_module` in DI. No registry changes needed.

## Bidirectional Wire Translation

### Incoming (wire → app)

```php
// ProtocolHandler::parseRawLine()
// Input: ":0AAAAAB NICK newnick"
// Output: IRCMessage{command: 'NICK', params: ['newnick'], ...}
```

Domain, Application, and Services never see wire format tokens. They only work with `IRCMessage` and domain events.

### Outgoing (app → wire)

```php
// Build intent in domain form
$message = new IRCMessage(command: 'NOTICE', params: [$targetUid], trailing: $text);

// Let protocol handler format to wire
$rawLine = $module->getHandler()->formatMessage($message);
$connection->writeLine($rawLine);
```

**No hardcoded sprintf** with a specific IRCd's format in shared code. The protocol handler owns the wire format.

## Documentation Reference

**BEFORE** implementing or modifying protocol behaviour, read the relevant docs:

| IRCd | Local Docs | Official Online |
|------|------------|-----------------|
| UnrealIRCd 6 | `docs/unrealircd/` | https://www.unrealircd.org/docs/ |
| InspIRCd 4 | `docs/inspircd/` | https://docs.inspircd.org/ |
| Base RFCs | `docs/rfc/` (rfc1459, rfc2812, rfc7194) | — |

Use **only** the documented version (Unreal 6, InspIRCd 4). Do not rely on docs for other versions.

## Files Affected

- `src/Application/Port/ProtocolModuleInterface.php`
- `src/Infrastructure/IRC/Protocol/ProtocolModuleRegistry.php`
- `src/Infrastructure/IRC/Protocol/<IrcName>/`
- `src/Infrastructure/IRC/Connection/ActiveConnectionHolder.php`
- `config/services.yaml` (tag `irc.protocol_module`)

## Quick Reference

```php
// Get active protocol module
$module = $this->connectionHolder->getProtocolModule();

// Available from module
$module->getHandler()                    // ProtocolHandler
$module->getServiceActions()             // ProtocolServiceActions
$module->getIntroductionFormatter()      // ServiceIntroductionFormatter
$module->getVhostCommandBuilder()       // VhostCommandBuilder
$module->getChannelModeSupport()        // ChannelModeSupportInterface
```