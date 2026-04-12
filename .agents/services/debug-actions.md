# Debug Actions - Rules for IRCop Commands

## Purpose

Sensitive commands executed by IRCops must log their actions to:
1. A shared debug channel (if configured)
2. A dedicated log file (`ircops.log`)

## Configuration

### Environment Variable

```env
IRCOPS_DEBUG_CHANNEL=#ircops
```

- If empty or undefined, no messages are sent to the channel
- File logging is always active

### Log Configuration

**File:** `config/packages/monolog.yaml`

```yaml
ircops_rotating:
    type:      rotating_file
    path:      '%kernel.logs_dir%/ircops.log'
    level:     info
    max_files: 26    # ~6 months (weekly rotation)
    channels:  [ircops]
```

## Architecture

### Interface

```php
namespace App\Application\Port;

interface ServiceDebugNotifierInterface
{
    public function isConfigured(): bool;
    
    public function ensureChannelJoined(): void;
    
    public function notify(
        string $operator,
        string $command,
        string $target,
        ?string $reason = null,
        array $extra = [],
    ): void;
}
```

### Service Implementations

Each service implements `ServiceDebugNotifierInterface`:

| Service | Class | Bot that sends messages |
|----------|-------|------------------------|
| OperServ | `OperServDebugNotifier` | OperServ |
| NickServ | `NickServDebugNotifier` | NickServ |
| ChanServ | `ChanServDebugNotifier` | ChanServ |
| MemoServ | (future) | MemoServ |

### Injection in Commands

```php
public function __construct(
    private readonly ServiceDebugNotifierInterface $debugNotifier,
    // ... other dependencies
) {}
```

## Commands that Require Debug

Only commands executed by IRCops require debug logging.

### OperServ (all commands are IRCop-only)
- `KILL` - Disconnect a user
- `IRCOP ADD/DEL` - Manage IRCops
- `ROLE ADD/DEL/MOD` - Manage roles and permissions
- Future: GLINE, KLINE, etc.

### NickServ (IRCop commands)
- `SASET` - Modify another user's settings
- `DROP` - Drop a nickname
- `SUSPEND` - Suspend a nickname
- `UNSUSPEND` - Unsuspend a nickname
- `RENAME` - Force a nick change
- `FORBID` - Forbid a nickname
- `FORBIDVHOST` - Forbid a vhost
- `UNFORBID` - Unforbid a nickname
- `USERIP` - View real IP

### ChanServ (IRCop commands)
- `DROP` - Drop a registered channel
- `SUSPEND` - Suspend a channel
- `UNSUSPEND` - Unsuspend a channel
- `FORBID` - Forbid a channel
- `UNFORBID` - Unforbid a channel

### ChanServ (`level_founder` — actions as founder)
When an IRCop with `chanserv.level_founder` permission executes a command on a channel they are **not the real founder of**, the action is automatically audited with permission `chanserv.level_founder`. This applies to commands like `SET`, `ACCESS`, `AKICK`, `OP`, `DEOP`, `VOICE`, `DEVOICE`, `HALFOP`, `DEHALFOP`, `ADMIN`, `DEADMIN`, `LEVELS`, `INVITE`, etc.

Commands that do NOT require identification (`getRequiredPermission() === null`) such as `HELP` and `INFO` are NOT audited, because `level_founder` does not grant any additional privilege for them — any user can use them regardless.

**The event is NOT emitted if**:
- The IRCop is the real channel founder (it's their own channel, no special audit needed)
- The command has no required permission (`null`) — e.g., HELP, INFO
- The command has no channel argument (e.g., HELP without a channel)

## Message Format

Each service sends messages with its own bot as prefix.

### On the debug channel (with IRC colors)

**Colors:**
- Blue (`\x0302`) for operator and target nicks
- Red (`\x0304`) for the command name

### OperServ KILL
```
<OperServ> Operator1 executes command KILL on BadUser. Reason: Flooding channels
           Nick: BadUser | Host: ~user@192.168.1.100 | IP: 10.0.0.55
```

### NickServ SASET (with option)
```
<NickServ> OperNick executes command SASET on TargetUser. Option: VHOST=ares.example.com
```

### NickServ SASET (PASSWORD - value hidden)
```
<NickServ> OperNick executes command SASET on TargetUser. Option: PASSWORD
```

### NickServ SUSPEND (with duration)
```
<NickServ> OperNick executes command SUSPEND on BadUser. Duration: 7d. Reason: Spam
```

### On the log file (no colors)

```
[2025-01-15T14:32:07+00:00] ircops.INFO: KILL {"operator":"Admin1","target":"BadUser","target_host":"~user@host.com","target_ip":"10.0.0.55","reason":"Flooding"} []
```

## Channel Protection

The debug channel (`IRCOPS_DEBUG_CHANNEL`) has restricted entry:
- Only identified IRCops and Roots can join
- Other users are automatically kicked by ChanServ
- The kick message uses the user's language

### Protection Flow

1. User joins channel → `UserJoinedChannelEvent`
2. `IrcopsDebugChannelProtectionSubscriber` checks:
   a. If the channel is not the debug channel → do nothing
   b. If it's ChanServ → allow
   c. If it's Root → allow
   d. If it's an identified IRCop → allow
   e. Otherwise → ChanServ kicks with translated message

## Examples

### OperServ KILL
```
<OperServ> Admin1 executes command KILL on BadUser. Reason: Flooding channels
           Nick: BadUser | Host: ~user@192.168.1.100 | IP: 10.0.0.55
```

### OperServ IRCOP ADD
```
<OperServ> AdminRoot executes command IRCOP ADD on NewOper. Role: OPER
           Nick: NewOper | Host: ~oper@isp.net
```

### NickServ DROP (IRCop-only)
```
<NickServ> OperNick executes command DROP on OldAccount. Reason: Requested by user
           Nick: OldAccount | Host: ~user@host.com
```

### ChanServ level_founder (IRCop acting as founder on another's channel)
```
<ChanServ> OperNick executes command SET on #channel. Option: URL=http://www.example.com
```

This is generated automatically when an IRCop with `chanserv.level_founder` permission executes any ChanServ command on a channel they are not the real founder of. The permission in the event is `chanserv.level_founder` and `extra` includes `founder_action: true`, `option` (sub-command like URL, DESC, ACCESS ADD, etc.), and `value` (the value argument).

Host and IP information is logged to the file (`ircops.log`) including `target_host` and `target_ip` fields but is not displayed on the debug channel.

## Adding a New Service

To add a debug notifier to a new service:

1. Create `src/Infrastructure/<Service>/Service/<Service>DebugNotifier.php`
2. Implement `ServiceDebugNotifierInterface`
3. Inject the `ircops` channel logger (`@monolog.logger.ircops`) and the service bot
4. Register in `ServiceDebugNotifierRegistry` with the `app.debug_notifier` tag in `services.yaml`
5. Add translations in `translations/<service>.en.yaml` (English) AND `translations/<service>.es.yaml` (Spanish). Every key in `.en.yaml` MUST also exist in `.es.yaml`.

## Translations

**You MUST create translations for ALL available languages: `en` (English) and `es` (Spanish).**
Every key added to `.en.yaml` MUST also be added to `.es.yaml` with the corresponding Spanish translation.

Translation keys in each service (shown for `.en.yaml` files):

```yaml
# translations/operserv.en.yaml
debug:
  action_message: "%operator% executes command %command% on %target%. Reason: %reason%"
  action_info: "Nick: %nick% | Host: %host% | IP: %ip%"
```

```yaml
# translations/nickserv.en.yaml
debug:
  action_message: "%operator% executes command %command% on %target%. %reason%"
  action_with_option: "%operator% executes command %command% on %target%. Option: %option%. %reason%"
  action_with_value: "%operator% executes command %command% on %target%. Option: %option%=%value%. %reason%"
  action_duration: "%operator% executes command %command% on %target%. Duration: %duration%. %reason%"
  prefix_reason: "Reason: %reason%"
```

```yaml
# translations/chanserv.en.yaml
debug_channel:
  kick_reason: "You are not authorized to join this channel."
```