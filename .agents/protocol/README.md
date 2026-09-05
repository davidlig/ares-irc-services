# Protocol Modules & Multi-IRCd Support

Use this skill when implementing protocol handlers, network state adapters, protocol service actions, or adding support for a new IRCd.

## One Module Per IRCd

Each supported IRCd has a **single runtime protocol module** for outbound/runtime capabilities. Incoming network-state adapters are separate router dependencies:

```
src/
├── Application/Port/ProtocolModuleInterface.php
├── Infrastructure/IRC/Runtime/ProtocolRuntimeModuleInterface.php
├── Infrastructure/IRC/Network/Adapter/
│   ├── UnrealIRCdNetworkStateAdapter.php
│   ├── UnrealUdbNetworkStateAdapter.php
│   └── InspIRCdNetworkStateAdapter.php
└── Infrastructure/IRC/Protocol/
    ├── ProtocolModuleRegistry.php
    ├── Unreal/
    │   ├── UnrealIRCdModule.php         (implements ProtocolRuntimeModuleInterface)
    │   ├── UnrealIRCdProtocolHandler.php
    │   ├── UnrealIRCdProtocolServiceActions.php
    │   └── UnrealIRCdChannelModeSupport.php
    ├── UnrealUdb/
    │   ├── UnrealUdbModule.php
    │   ├── UnrealUdbProtocolHandler.php
    │   ├── UnrealUdbProtocolServiceActions.php
    │   ├── UnrealUdbChannelModeSupport.php
    │   └── Subscriber/
    │       └── UdbChannelSyncSubscriber.php
    ├── InspIRCd/
    │   ├── InspIRCdModule.php
    │   ├── InspIRCdProtocolHandler.php
    │   ├── InspIRCdProtocolServiceActions.php
    │   └── InspIRCdChannelModeSupport.php
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
            'unrealudb' => $this->unrealUdbHandler->parse($rawLine),
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

**No hardcoded sprintf** with a specific IRCd's format in shared code. The protocol handler and protocol service actions own the wire format.

## Protocol Service Actions (`ProtocolServiceActionsInterface`)

All wire-level commands executed on the IRC network are encapsulated in `ProtocolServiceActionsInterface`:

- `introduceService($serverSid, $nick, $ident, $host, $uid, $realname, $serviceKey)`: Introduces pseudo-client bots on burst.
- `setUserVhost($serverSid, $targetUid, $vhost, $cloakedHost)`: Sets or clears user vhost (e.g. `CHGHOST`, `ENCAP CHGHOST`, `MODE +x`).
- `setUserAccount($serverSid, $targetUid, $account)`: Sets/unsets account name (e.g. `SVS2MODE +d`, `ENCAP ACCOUNT`).
- `setUserMode($serverSid, $targetUid, $modes)`: Applies mode changes to users.
- `forceNick($serverSid, $targetUid, $newNick)`: Forces a nick change.
- `killUser($serverSid, $targetUid, $reason)`: Kills a user connection.
- `setChannelModes($serverSid, $channelName, $modeString, $params, $serviceUid, $channelTimestamp)`: Changes channel modes (`+r`, `+P`, etc.).
- `setChannelMemberMode($serverSid, $channelName, $targetUid, $modeLetter, $add, $serviceUid, $channelTimestamp)`: Adds or removes member status modes.
- `inviteUserToChannel($serverSid, $channelName, $targetUid, $serviceUid, $channelTimestamp)`: Invites a user with protocol timestamp support.
- `joinChannelAsService($serverSid, $channelName, $serviceUid, $maxPrefixLetter, $channelTimestamp)`: Joins a service bot to a channel.
- `partChannelAsService($serverSid, $channelName, $serviceUid)`: Parts a service bot from a channel.
- `setChannelTopic($serverSid, $channelName, $topic, $serviceUid, $channelCreationTs)`: Changes channel topic.
- `kickFromChannel($serverSid, $channelName, $targetUid, $reason, $serviceUid)`: Kicks a user from a channel.
- `addGline()` / `removeGline()`: Manages network-wide bans.
- `introducePseudoClient()` / `quitPseudoClient()`: Manages temporary pseudo-clients.

## Protocol-Specific Capabilities — Optional Ports (NON-NEGOTIABLE)

The shared surface above is frozen for cross-protocol behavior. A capability that only one IRCd supports MUST NOT be added to it. Pattern:

1. Create a **new optional port** in `src/Application/Port/` (e.g. `OperclassServiceActionsInterface` — Unreal `SVSO`, UnrealUdb `N::oper`; InspIRCd does not implement it).
2. Only the protocol modules that support the capability implement it (they may implement the mandatory interface plus the optional one).
3. Consumers feature-detect with `instanceof` on `$module->getServiceActions()` — never add capability methods to `ProtocolModuleInterface`/`ProtocolServiceActionsInterface`/`ProtocolHandlerInterface`.
4. Protocol-specific behavior (session tick, deadlines, wire workarounds) lives **inside the protocol's own namespace** and is driven from its own handler — never from `IRCClient`, `AbstractProtocolHandler`, or the frozen `UnrealFamily` traits.
5. If a change forces edits outside the protocol's own namespace and its own tests, it is a design violation: redesign with a new port, tag, or domain event instead.

## Documentation Reference

**BEFORE** implementing or modifying protocol behaviour, read the relevant docs:

| IRCd | Local Docs | Official Online |
|------|------------|-----------------|
| UnrealIRCd 6 | `docs/unrealircd/` | https://www.unrealircd.org/docs/ |
| UnrealIRCd-UDB 6 | `docs/unrealudb/` | https://github.com/davidlig/unrealircd-udb |
| InspIRCd 4 | `docs/inspircd/` | https://docs.inspircd.org/ |
| Base RFCs | `docs/rfc/` (rfc1459, rfc2812, rfc7194) | — |

Use **only** the documented version (Unreal 6, InspIRCd 4). Do not rely on docs for other versions.

## Files Affected

- `src/Application/Port/ProtocolModuleInterface.php` — FROZEN for cross-protocol capabilities
- `src/Application/Port/ProtocolServiceActionsInterface.php` — FROZEN for cross-protocol capabilities
- `src/Application/Port/<Capability>ServiceActionsInterface.php` — optional capability ports (new files)
- `src/Infrastructure/IRC/Protocol/ProtocolModuleRegistry.php`
- `src/Infrastructure/IRC/Protocol/<IrcName>/`
- `src/Infrastructure/IRC/Network/Adapter/<IrcName>NetworkStateAdapter.php`
- `src/Infrastructure/IRC/Connection/ActiveConnectionHolder.php`
- `config/services.yaml` (tag `irc.protocol_module`)

## Quick Reference

```php
// Get active protocol module
$module = $this->connectionHolder->getProtocolModule();

// Shared module capabilities
$module->getServiceActions()             // ProtocolServiceActionsInterface
$module->getIntroductionFormatter()      // ServiceIntroductionFormatterInterface
$module->getChannelModeSupport()         // ChannelModeSupportInterface
$module->getUserModeSupport()            // UserModeSupportInterface
$module->getNickReservation()            // ?ServiceNickReservationInterface

// Runtime code narrows the module to ProtocolRuntimeModuleInterface
$module->getHandler()                    // ProtocolHandlerInterface

// Incoming state is routed separately through NetworkStateAdapterInterface::handleMessage()
```
