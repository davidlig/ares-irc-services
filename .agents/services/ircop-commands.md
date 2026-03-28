# IRCOP Commands Skill

This document describes the unified permission system for IRCOP commands across all IRC services (NickServ, ChanServ, MemoServ, OperServ).

## Overview

Each IRCOP-only command has a permission string (e.g., `NICKSERV_DROP`, `CHANSERV_SUSPEND`) that is:
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

1. Command handler calls `$context->getRequiredPermission()` (returns `NICKSERV_DROP` or similar)
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

## Adding a New IRCOP Permission

### Step 1: Add Permission Constant

In the appropriate service's IrcopPermission class:

```php
// src/Application/ChanServ/Security/ChanServIrcopPermission.php
public const string MY_NEW_COMMAND = 'CHANSERV_MYNEWCOMMAND';

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

### NickServ, ChanServ, MemoServ Permissions

**Note:** Permission definitions for these services are not yet implemented. Permissions will be added as IRCOP-only commands are developed.

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
    ->with('CHANSERV_DROP', self::anything())
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