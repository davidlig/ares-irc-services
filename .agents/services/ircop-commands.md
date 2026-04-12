# IRCOP Commands Skill

This document describes the unified permission system for IRCOP commands across all IRC services (NickServ, ChanServ, MemoServ, OperServ).

## Overview

Each IRCOP-only command has a permission string (e.g., `nickserv.drop`, `chanserv.suspend`) that is:
1. Stored centrally in the `oper_permissions` database table
2. Assigned to roles via `ROLE PERMS <role> ADD <permission>`
3. Checked by `IrcopPermissionVoter` when commands are executed

## Architecture

### Permission Format

Permissions use lowercase dot notation format `<service>.<command>`:
- `operserv.kill` - KILL command
- `nickserv.drop` - Drop registered nicks
- `chanserv.suspend` - Suspend registered channels
- `operserv.admin.add` - OperServ admin commands (nested)

### Key Components

#### PermissionProviderInterface

Location: `src/Application/Security/PermissionProviderInterface.php`

Interface that each service implements to declare its IRCOP permissions:

```php
interface PermissionProviderInterface
{
    public function getServiceName(): string;
    public function getPermissions(): array;
}
```

#### PermissionRegistry

Location: `src/Application/Security/PermissionRegistry.php`

Service that collects permissions from all providers via tagged_iterator:

```php
$registry->getAllPermissions();       // All permissions, sorted
$registry->getPermissionsByService(); // Grouped by service
```

#### Permission Classes

Each service has an `IrcopPermission` class:

- `NickServIrcopPermission` - `src/Application/NickServ/Security/NickServIrcopPermission.php`
- `ChanServIrcopPermission` - `src/Application/ChanServ/Security/ChanServIrcopPermission.php`
- `MemoServIrcopPermission` - `src/Application/MemoServ/Security/MemoServIrcopPermission.php`
- `OperServIrcopPermission` - `src/Application/OperServ/Security/OperServIrcopPermission.php`

### Authorization Flow

1. Command handler calls `$context->getRequiredPermission()` (returns `nickserv.drop` or similar)
2. Service calls `$authorizationChecker->isGranted($permission, $context)`
3. `IrcopPermissionVoter` checks:
   - User has ROLE_OPER flag from IRCd
   - If root user → grant immediately
   - If user has IRCOP account → check role permissions via `IrcopAccessHelper::hasPermission()`
4. If granted → command executes
5. If denied → reply with `error.permission_denied`

### Voters

#### IdentifiedVoter

Checks `IDENTIFIED` permission - verifies user has identified (+r mode):

```php
// In command handler
public function getRequiredPermission(): ?string
{
    return 'IDENTIFIED';
}
```

#### IrcopPermissionVoter

Checks IRCOP-specific permissions:

```php
// In command handler
public function getRequiredPermission(): ?string
{
    return 'NICKSERV_DROP';
}
```

#### ChanServLevelFounderVoter

Checks `chanserv.level_founder` permission — allows IRCops to act as channel founder:

```php
// Checked in ChanServService::dispatch():
$isLevelFounder = $this->authorizationChecker->isGranted(ChanServPermission::LEVEL_FOUNDER, $context);
```

Decision flow:
1. Root users identified → grant immediately (bypass +o requirement)
2. Must have `ROLE_OPER` (IRC operator)
3. `IrcopAccessHelper::hasPermission($nickId, $nickLower, 'chanserv.level_founder')`

This voter is registered in `config/services.yaml` alongside other voters in `AccessDecisionManager`.

### ChanServCommandInterface: Channel Status Methods

Every ChanServ command MUST implement two methods that control whether the command can run on channels with specific statuses:

#### `allowsSuspendedChannel(): bool`

- `true`: Command works on suspended channels (SUSPEND, UNSUSPEND, DROP, INFO, FORBID, UNFORBID, HELP)
- `false`: Command is blocked on suspended channels (default for most commands)

When `isLevelFounder` is true, suspended channel check is bypassed — the IRCop can use any command on a suspended channel.

#### `allowsForbiddenChannel(): bool`

- `true`: Command works on forbidden channels — **ONLY** FORBID, UNFORBID, and INFO
- `false`: Command is blocked on forbidden channels (default for ALL other commands)

**CRITICAL RULE: Only FORBID, UNFORBID, and INFO return `true`.** All other commands, even those with `level_founder`, return `false`. This means even an IRCop with `chanserv.level_founder` CANNOT use SET, OP, ACCESS, etc. on a forbidden channel. The `isLevelFounder` flag does NOT bypass the forbidden channel check.

**When adding a new ChanServ command, you MUST implement `allowsForbiddenChannel()`.** Return `false` by default. Return `true` ONLY if the command must work on a forbidden channel (extremely rare).

## Automatic Audit Logging (NEW)

### Overview

IRCOP command executions are automatically logged for audit purposes. The system uses an event-driven architecture:

1. **Command executes** and `getRequiredPermission()` returns an IRCOP permission
2. **Service emits** `IrcopCommandExecutedEvent` with audit data
3. **Subscriber filters** by permission type (only IRCOP permissions are logged)
4. **DebugActionPort logs** to channel and file

### Components

#### IrcopCommandExecutedEvent

Location: `src/Domain/IRC/Event/IrcopCommandExecutedEvent.php`

Event emitted after any command with a `getRequiredPermission()`:

```php
new IrcopCommandExecutedEvent(
    operatorNick: 'AdminUser',
    commandName: 'KILL',
    permission: 'operserv.kill',
    target: 'BadUser',
    targetHost: 'user@host.com',
    targetIp: '10.0.0.1',
    reason: 'Flooding',
    extra: ['duration' => '1h'],
);
```

#### AuditableCommandInterface

Location: `src/Application/Command/AuditableCommandInterface.php`

Interface for commands that need to provide audit data:

```php
interface AuditableCommandInterface
{
    public function getAuditData(object $context): ?IrcopAuditData;
}
```

#### IrcopAuditData

Location: `src/Application/Command/IrcopAuditData.php`

DTO for audit data:

```php
new IrcopAuditData(
    target: 'BadUser',
    targetHost: 'user@host.com',
    targetIp: '10.0.0.1',
    reason: 'Flooding',
    extra: ['duration' => '1h'],
);
```

#### IrcopPermissionDetector

Location: `src/Application/Security/IrcopPermissionDetector.php`

Detects if a permission is IRCOP-level:

```php
$detector->isIrcopPermission('operserv.kill');     // true
$detector->isIrcopPermission('IDENTIFIED');         // false
$detector->isIrcopPermission('nickserv.drop');      // true
```

#### IrcopCommandAuditSubscriber

Location: `src/Infrastructure/IRC/Subscriber/IrcopCommandAuditSubscriber.php`

Listens to `IrcopCommandExecutedEvent` and calls `DebugActionPort->log()`.

### How It Works

#### For Services (Automatic)

In each `*Service::dispatch()` method, after command execution:

```php
$requiredPermission = $handler->getRequiredPermission();
if (null !== $requiredPermission) {
    $auditData = $handler instanceof AuditableCommandInterface
        ? $handler->getAuditData($context)
        : null;
    
    $this->eventDispatcher->dispatch(new IrcopCommandExecutedEvent(
        operatorNick: $sender->nick,
        commandName: $cmdName,
        permission: $requiredPermission,
        target: $auditData?->target,
        targetHost: $auditData?->targetHost,
        targetIp: $auditData?->targetIp,
        reason: $auditData?->reason,
        extra: $auditData?->extra ?? [],
    ));
}
```

#### For ChanServ level_founder (Automatic)

When an IRCop with `chanserv.level_founder` permission executes a ChanServ command (SET, ACCESS, OP, etc.) on a channel they are **not** the real founder of, `ChanServService::dispatch()` automatically emits an `IrcopCommandExecutedEvent` with permission `chanserv.level_founder`.

This is independent of the normal audit flow — it happens **after** the handler executes, **and only if**:
1. `isLevelFounder === true` (the IRCop has the `chanserv.level_founder` permission)
2. `requiredPermission !== null` (commands like HELP and INFO that anyone can use are excluded)
3. The command has a valid channel argument (`#channel`)
4. The IRCop is **not** the real channel founder (`!$channel->isFounder($account->getId())`)

The event includes `extra: ['founder_action' => true]` (plus `option` and `value` for sub-commands like SET URL) to distinguish level_founder actions from normal IRCop commands.

**Events are NOT emitted** when:
- `isLevelFounder === false` (normal user)
- `requiredPermission === null` (commands anyone can use, like HELP, INFO)
- The IRCop **is** the real channel founder (they're acting on their own channel)
- The command has no channel argument (e.g., HELP without a channel)

#### For Commands (Optional)

Commands that need to log specific audit data implement `AuditableCommandInterface`:

```php
final class KillCommand implements OperServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;
    
    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
    
    public function execute(OperServContext $context): void
    {
        // ... execute logic ...
        
        // Set audit data before return
        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            targetHost: $target->ident . '@' . $target->hostname,
            targetIp: $target->ipBase64,
            reason: $reason,
        );
    }
}
```

Commands that don't implement the interface will still be logged, but with empty target/reason fields.

### What Gets Logged

| Permission | Logged? | Why |
|------------|---------|-----|
| `operserv.kill` | ✅ Yes | IRCOP permission |
| `operserv.gline` | ✅ Yes | IRCOP permission |
| `nickserv.drop` | ✅ Yes | IRCOP permission |
| `chanserv.drop` | ✅ Yes | IRCOP permission |
| `chanserv.suspend` | ✅ Yes | IRCOP permission |
| `chanserv.forbid` | ✅ Yes | IRCOP permission |
| `chanserv.level_founder` | ✅ Yes | IRCOP permission (auto-logged when non-founder uses level_founder) |
| `IDENTIFIED` | ❌ No | User permission, not IRCOP |
| `null` | ❌ No | No permission required |

### Migration from Manual Logging

**Before (old way - manual):**

```php
// In KillCommand::execute()
$this->debug->log(
    operator: $operatorNick,
    command: 'KILL',
    target: $targetNick,
    // ...
);
```

**After (new way - automatic):**

```php
// No manual logging needed - the subscriber handles it
// Just implement AuditableCommandInterface if you need specific audit data
```

## Adding a New IRCOP Permission

### Step 1: Add Permission Constant

In the appropriate service's IrcopPermission class:

```php
// src/Application/ChanServ/Security/ChanServIrcopPermission.php
public const string MY_NEW_COMMAND = 'chanserv.mynewcommand';

public function getPermissions(): array
{
    return [
        self::DROP,
        self::SUSPEND,
        // ... existing
        self::MY_NEW_COMMAND,  // Add here
    ];
}
```

### Step 2: Set Permission in Command Handler

```php
// src/Application/ChanServ/Command/Handler/MyNewCommand.php
public function getRequiredPermission(): ?string
{
    return ChanServIrcopPermission::MY_NEW_COMMAND;
}
```

### Step 3: Use Authorization in Service

The service automatically handles authorization via `AuthorizationChecker`:

```php
// ChanServService already checks authorization:
$requiredPermission = $handler->getRequiredPermission();
if (null !== $requiredPermission && !$this->authorizationChecker->isGranted($requiredPermission, $context)) {
    if ('IDENTIFIED' === $requiredPermission) {
        $context->reply('error.not_identified');
    } else {
        $context->reply('error.permission_denied');
    }
    return;
}
```

### Step 4: Grant Permission via OperServ

```
/OPERV ROLE PERMS myrole ADD operserv.admin.add
```

## Managing Permissions

### Listing Available Permissions

```
/OPERV ROLE PERMS <role> LIST
```

Shows:
- Assigned permissions (permissions the role has)
- Available permissions (all defined permissions not assigned)

### Adding Permission to Role

```
/OPERV ROLE PERMS <role> ADD <permission>
```

### Removing Permission from Role

```
/OPERV ROLE PERMS <role> DEL <permission>
```

## Permission Constants

### OperServ Permissions

- `operserv.kill` - KILL command (forcibly disconnect a user from the network)

### ChanServ Permissions

- `chanserv.drop` - Drop a registered channel
- `chanserv.suspend` - Suspend/unsuspend a channel
- `chanserv.forbid` - Forbid/unforbid a channel
- `chanserv.level_founder` - Act as channel founder (bypass all access level checks, use SET FOUNDER without token)

### NickServ Permissions

- `nickserv.drop` - Drop a registered nickname
- `nickserv.suspend` - Suspend a nickname
- `nickserv.forbid` - Forbid/unforbid nicknames
- `nickserv.saset` - Modify another user's settings
- `nickserv.userip` - View real IP/Host of a user
- `nickserv.rename` - Force rename a connected user
- `nickserv.forbidvhost` - Forbid/allow vhost patterns
- `nickserv.noexpire` - Protect a nickname from expiration
- `nickserv.history` - View and manage nickname action history

## ChanServ `level_founder` Permission

### Overview

The `chanserv.level_founder` permission allows an IRCop to act as if they were the channel founder. This is NOT a separate command — it modifies the behavior of existing ChanServ commands.

### What `level_founder` Bypasses

| Check | Normal behavior | With `level_founder` |
|-------|----------------|---------------------|
| `isFounder()` check (SET FOUNDER, SET SUCCESSOR, LEVELS) | Only actual founder | Bypassed; IRCop treated as founder |
| `requireLevel()` (SET, ACCESS, AKICK, OP, DEOP, etc.) | Must meet level threshold | Bypassed; IRCop has founder-level access |
| Token requirement (SET FOUNDER) | Two-step email token verification | Skipped; direct founder transfer |
| Channel suspended block | Command blocked | Command allowed |
| Channel forbidden block | Command blocked | Command blocked (see rules below) |
| **Audit logging** | Not logged (IDENTIFIED permission) | **Logged** with `chanserv.level_founder` permission |

### Forbidden Channel Rules (CRITICAL)

**Rule: Only FORBID, UNFORBID, and INFO can operate on forbidden channels.** All other commands — including those with `level_founder` — are blocked on forbidden channels.

This is enforced by the `allowsForbiddenChannel()` method on `ChanServCommandInterface`:

- `allowsForbiddenChannel() = true`: FORBID, UNFORBID, INFO
- `allowsForbiddenChannel() = false`: ALL other commands (DROP, SUSPEND, SET, OP, etc.)

**When adding a new ChanServ command, you MUST implement `allowsForbiddenChannel()`.** Return `true` ONLY if the command must work on a forbidden channel (this should be extremely rare). Return `false` by default.

### Architecture

The `isLevelFounder` flag is computed in `ChanServService::dispatch()`:

```
1. Check required permission (IDENTIFIED, chanserv.drop, etc.)
2. Compute isLevelFounder = isGranted('chanserv.level_founder', context)
3. Create ChanServContext with isLevelFounder flag
4. Check forbidden channel (allowsForbiddenChannel only — isLevelFounder does NOT bypass)
5. Check suspended channel (allowsSuspendedChannel + isLevelFounder bypass)
6. Execute handler
```

### Voter Implementation

`ChanServLevelFounderVoter` (in `src/Infrastructure/ChanServ/Security/Voter/`) checks:
1. Root users identified → grant immediately
2. `ROLE_OPER` check → must be IRC operator
3. `IrcopAccessHelper::hasPermission($nickId, $nickLower, 'chanserv.level_founder')` → role permission check</think>

## Root Users

Root users (configured in `OPERSERV_ROOT_USERS` environment variable) have special privileges:

### Requirements
- **Must be identified** (`/NICKSERV IDENTIFY`) to use IRCOP commands
- **Do NOT need** IRC operator status (mode `+o`) from the IRCd

### Permissions
- Bypass all permission checks
- Access to all IRCOP commands across all services (NickServ, ChanServ, MemoServ, OperServ)
- No need for role assignments in OperServ

### Implementation

The `IrcopPermissionVoter` checks root users BEFORE requiring `ROLE_OPER`:

```php
// Root users identified have all permissions automatically (bypass +o requirement)
if ($sender->isIdentified && $this->accessHelper->isRoot(strtolower($sender->nick))) {
    return true;
}

// Must have ROLE_OPER (be an IRC operator) - only for non-root users
if (!in_array(IrcServiceUser::ROLE_OPER, $user->getRoles(), true)) {
    return false;
}
```

### Configuration

In `.env`:
```
OPERSERV_ROOT_USERS=ares,nick1,nick2
```

### Security Model

| User Type | Requires +o | Requires IDENTIFY | Permission Check |
|-----------|-------------|-------------------|------------------|
| ROOT user | ❌ No | ✅ Yes | Bypass all |
| IRCOP with role | ✅ Yes | ✅ Yes | Role permissions |
| IRCOP without role | ✅ Yes | ✅ Yes | Denied |
| Regular user | ✅ Yes | ✅ Yes | Denied |

## Testing

### Unit Tests

Test permission classes:

```php
// tests/Application/ChanServ/Security/ChanServIrcopPermissionTest.php
public function getPermissionsReturnsAllPermissions(): void
{
    $permission = new ChanServIrcopPermission();
    $permissions = $permission->getPermissions();
    
    self::assertContains(ChanServIrcopPermission::DROP, $permissions);
    self::assertContains(ChanServIrcopPermission::SUSPEND, $permissions);
}
```

### Integration Tests

Test voter authorization:

```php
$authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
$authorizationChecker->expects(self::once())
    ->method('isGranted')
    ->with('chanserv.drop', self::anything())
    ->willReturn(true);
```

## Migration Notes

### From isOperOnly() to getRequiredPermission()

Before (old architecture):
```php
public function isOperOnly(): bool
{
    return true;
}

public function getRequiredPermission(): ?string
{
    return null;
}
```

After (new architecture):
```php
public function isOperOnly(): bool
{
    return false; // Not used anymore
}

public function getRequiredPermission(): ?string
{
    return 'CHANSERV_DROP';
}
```

The `isOperOnly()` method is deprecated. All authorization now goes through `getRequiredPermission()` + `AuthorizationChecker`.

## Displaying IRCop Commands in HELP

All services (NickServ, ChanServ, MemoServ, OperServ) must show IRCop commands in HELP
based on the user's permissions:

### Implementation Pattern

Each service's `HelpFormatterContextAdapter` must implement:
- `getIrcopCommands()`: Returns commands the user has permission for
- `hasIrcopAccess()`: Returns true if user is root or IRCop with permissions

### Permission Detection

Use `IrcopPermissionDetector::isIrcopPermission(string $permission): bool` to detect
if a permission string is an IRCop permission (format: `service.command`).

Then verify with `IrcopAccessHelper::hasPermission()` for non-root users.

### Display Format

When a user has IRCop permissions, show a separated section:

```
─────────────────────────────────────

The following commands are available for IRCOPS:
  USERIP    Gets the real IP/Host address of the user.
─────────────────────────────────────
```

### Root User Special Case

Root users (configured in `OPERSERV_ROOT_USERS`) see ALL IRCop commands automatically
without needing explicit role assignments.

### Code Example: NickServ HelpFormatterContextAdapter

```php
public function getIrcopCommands(): iterable
{
    $sender = $this->context->sender;
    $account = $this->context->senderAccount;
    
    // Must be identified to have IRCop permissions
    if (null === $sender || null === $account) {
        return [];
    }
    
    $nickLower = strtolower($sender->nick);
    
    // Root users see all IRCop commands
    if ($this->rootRegistry->isRoot($nickLower)) {
        return $this->filterIrcopCommands($this->context->getRegistry()->all());
    }
    
    // Must be IRCop and have permissions
    if (!$sender->isOper) {
        return [];
    }
    
    // Filter by permissions
    return $this->filterByPermission(
        $this->context->getRegistry()->all(),
        $account->getId(),
        $nickLower
    );
}

public function hasIrcopAccess(): bool
{
    $sender = $this->context->sender;
    $account = $this->context->senderAccount;
    
    if (null === $sender || null === $account) {
        return false;
    }
    
    $nickLower = strtolower($sender->nick);
    
    // Root users always have IRCop access
    if ($this->rootRegistry->isRoot($nickLower)) {
        return true;
    }
    
    // IRCops with at least one permission
    if ($sender->isOper) {
        $servicePermissions = $this->permissionRegistry->getPermissionsByService()['NickServ'] ?? [];
        foreach ($servicePermissions as $permission) {
            if ($this->accessHelper->hasPermission($account->getId(), $nickLower, $permission)) {
                return true;
            }
        }
    }
    
    return false;
}
```