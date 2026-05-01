# Commands — Permissions & Authorization

Use this skill when configuring authorization for IRC service commands.

---

## Permission Architecture

Authorization check happens in `{Service}Service::dispatch()`:

```
User sends PRIVMSG → dispatch() → getRequiredPermission()
                                        │
                    ┌───────────────────┼───────────────────┐
                    ▼                   ▼                   ▼
                  null           'IDENTIFIED'        'service.permission'
                    │                   │                   │
                    ▼                   ▼                   ▼
               No check         IdentifiedVoter    IrcopPermissionVoter
                    │                   │                   │
                    └───────────────────┴───────────────────┘
                                        │
                               isGranted() → YES → execute()
                               isGranted() → NO → error reply
```

---

## Permission Types

| Type | Return Value | Checked By | Example Commands |
|------|--------------|------------|-----------------|
| Public (no auth) | `null` | None | HELP, REGISTER |
| Identified | `'IDENTIFIED'` | `IdentifiedVoter` | INFO (extended) |
| Owner | `NickServPermission::IDENTIFIED_OWNER` | `NickServIdentifiedOwnerVoter` | SET, DROP (self) |
| IRCop | `OperServPermission::KILL` | `IrcopPermissionVoter` | KILL, GLINE, IRCOP ADD |

### Public Command

```php
public function getRequiredPermission(): ?string
{
    return null;  // Anyone can use
}
```

### IDENTIFIED Command

```php
public function getRequiredPermission(): ?string
{
    return 'IDENTIFIED';  // Must have +r mode
}
```

The service auto-replies `error.not_identified` if the user is not identified.

### Owner Command

```php
// File: src/Application/NickServ/Security/NickServPermission.php
final readonly class NickServPermission
{
    public const string IDENTIFIED_OWNER = 'nickserv_identified_owner';
}

// In the command handler
public function getRequiredPermission(): ?string
{
    return NickServPermission::IDENTIFIED_OWNER;
}
```

### IRCop Command

```php
// File: src/Application/OperServ/Security/OperServPermission.php
final class OperServPermission
{
    public const string KILL = 'operserv.kill';
}

// File: src/Application/OperServ/Command/Handler/KillCommand.php
public function getRequiredPermission(): ?string
{
    return OperServPermission::KILL;
}
```

---

## Root-Only Commands

Some commands only root users can execute:

```php
public function execute(OperServContext $context): void
{
    if (null === $context->sender) {
        return;
    }

    if (!$context->isRoot()) {
        $context->reply('error.root_only');

        return;
    }

    // Root-only logic
}
```

---

## Authorization Flow in Detail

```php
// In {Service}Service::dispatch()
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

---

## Synchronizing Permission Classes (CRITICAL)

These files must be kept synchronized when adding a new IRCop permission:

1. **`{Service}Permission.php`** — Constants used by Voters
2. **`{Service}IrcopPermission.php`** — `PermissionProviderInterface` implementation
3. **`translations/{service}.en.yaml`** AND **`translations/{service}.es.yaml`** — Permission descriptions (ALL languages)

### Adding a New IRCop Permission

```php
// 1. Add constant in XxxPermission.php
final class NickServPermission
{
    public const string DROP = 'nickserv.drop';
}

// 2. Add to XxxIrcopPermission.php
final readonly class NickServIrcopPermission implements PermissionProviderInterface
{
    public function getPermissions(): array
    {
        return [
            NickServPermission::DROP,
        ];
    }
}
```

```yaml
# 3. Add descriptions in ALL languages
# translations/nickserv.en.yaml
permissions:
  nickserv.drop: "Drop a registered nickname"

# translations/nickserv.es.yaml
permissions:
  nickserv.drop: "Eliminar un nickname registrado"
```

These descriptions appear in `ROLE PERMS <role> LIST`:

```
-OperServ- --- Permissions for ADMIN ---
-OperServ- Assigned:
-OperServ-   nickserv.drop - Drop a registered nickname
-OperServ- Available:
-OperServ-   nickserv.info - View extended info of any nickname
```

---

## Custom Voters

If a permission requires logic beyond the standard voters:

```php
// src/Infrastructure/NickServ/Security/Voter/NickServIdentifiedOwnerVoter.php
final class NickServIdentifiedOwnerVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return NickServPermission::IDENTIFIED_OWNER === $attribute
            && $subject instanceof NickServContext;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $context = $subject;
        $sender = $context->sender;
        $account = $context->senderAccount;

        if (null === $sender || null === $account) {
            return false;
        }

        if (!$sender->isIdentified) {
            return false;
        }

        // Owner: sender's nick matches account nickname
        return 0 === strcasecmp($sender->nick, $account->getNickname());
    }
}
```

Register in `config/services.yaml`:

```yaml
Symfony\Component\Security\Core\Authorization\AccessDecisionManager:
    arguments:
        $voters:
            - '@App\Infrastructure\NickServ\Security\Voter\NickServIdentifiedOwnerVoter'
            - '@App\Infrastructure\Security\Voter\IdentifiedVoter'
            - '@App\Infrastructure\Security\Voter\IrcopPermissionVoter'
```

---

## Existing Security Files

| Service | Permission Constants | IRCop Permission Provider | Custom Voters |
|---------|---------------------|--------------------------|---------------|
| NickServ | `NickServPermission` | `NickServIrcopPermission` | `NickServIdentifiedOwnerVoter`, `NickServSasetVoter` |
| ChanServ | `ChanServPermission` | `ChanServIrcopPermission` | `ChanServLevelFounderVoter` |
| MemoServ | — | `MemoServIrcopPermission` | — |
| OperServ | `OperServPermission` | `OperServIrcopPermission` | — |

---

## Related Skills

- `.agents/services/commands.md` — Command handler interface and structure
- `.agents/services/ircop-commands.md` — IRCop permission system details
- `.agents/services/commands-testing.md` — Testing authorization in commands
