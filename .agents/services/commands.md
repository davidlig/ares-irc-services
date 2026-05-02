# Commands — Creating New IRC Service Commands

This document describes how to create new commands for IRC services (NickServ, ChanServ, MemoServ, OperServ).

## Command Dispatch Flow

```
User sends: /msg NickServ REGISTER password email
                      │
                      ▼
              NickServService::dispatch()
                      │
        ┌─────────────┼─────────────┐
        │             │             │
        ▼             ▼             ▼
    Parse text   Find handler   Build Context
        │             │             │
        └─────────────┴─────────────┘
                      │
                      ▼
           handler->getRequiredPermission()
                      │
        ┌─────────────┼─────────────┐
        │             │             │
       null       'identified'   'nickserv.drop'
        │             │             │
        ▼             ▼             ▼
    No check    IdentifiedVoter  IrcopPermissionVoter
        │             │             │
        └─────────────┴─────────────┘
                      │
                      ▼
               Granted? ─── NO ──▶ Reply: error response
                      │
                     YES
                      │
                      ▼
          Check argument count (getMinArgs)
                      │
                      ▼
               handler->execute($context)
```

## File Location

```
src/Application/{Service}/Command/Handler/{CommandName}.php
```

## Command Interface

Each command handler must implement `{Service}CommandInterface`:

```php
interface NickServCommandInterface
{
    public function getName(): string;           // Primary command name (uppercase)
    public function getAliases(): array;         // Alternative names (e.g., ['ID'] for IDENTIFY)
    public function getMinArgs(): int;           // Minimum arguments required
    public function getSyntaxKey(): string;      // Translation key for syntax line
    public function getHelpKey(): string;        // Translation key for full help
    public function getOrder(): int;             // Display order in HELP (lower = first)
    public function getShortDescKey(): string;   // Translation key for one-line description
    public function getSubCommandHelp(): array;  // Sub-commands for compound commands
    public function isOperOnly(): bool;          // DEPRECATED — use getRequiredPermission()
    public function getRequiredPermission(): ?string;  // Permission attribute or null
    public function execute(NickServContext $context): void;
}
```

### ChanServ Extensions

`ChanServCommandInterface` adds two extra methods:

```php
public function allowsSuspendedChannel(): bool;
public function allowsForbiddenChannel(): bool;
```

- `allowsForbiddenChannel() = true`: ONLY FORBID, UNFORBID, and INFO
- `allowsForbiddenChannel() = false`: ALL other commands — enforced in `ChanServService::dispatch()` before any handler runs, cannot be bypassed even by `isLevelFounder`
- `allowsSuspendedChannel() = true`: SUSPEND, UNSUSPEND, DROP, INFO, FORBID, UNFORBID, HELP
- `allowsSuspendedChannel() = false`: All other commands (bypassed by `isLevelFounder`)

## Basic Command Template

```php
<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;

final readonly class MyCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly SomeRepositoryInterface $repository,
        private readonly int $someConfigurableParam,
    ) {}

    public function getName(): string { return 'MYCOMMAND'; }
    public function getAliases(): array { return ['MC']; }
    public function getMinArgs(): int { return 2; }
    public function getSyntaxKey(): string { return 'mycommand.syntax'; }
    public function getHelpKey(): string { return 'mycommand.help'; }
    public function getOrder(): int { return 10; }
    public function getShortDescKey(): string { return 'mycommand.short'; }
    public function getSubCommandHelp(): array { return []; }
    public function isOperOnly(): bool { return false; }
    public function getRequiredPermission(): ?string { return null; }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $arg1 = $context->args[0];
        $arg2 = $context->args[1];

        // Business logic...

        $context->reply('mycommand.done', ['%arg1%' => $arg1]);
    }
}
```

## Sub-Commands (SET-style)

For compound commands like SET, ACCESS:

```php
final readonly class SetCommand implements NickServCommandInterface
{
    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    public function __construct(
        SetPasswordHandler $setPasswordHandler,
        SetEmailHandler $setEmailHandler,
    ) {
        $this->handlers = [
            'PASSWORD' => $setPasswordHandler,
            'EMAIL' => $setEmailHandler,
        ];
    }

    public function getName(): string { return 'SET'; }
    public function getMinArgs(): int { return 2; }
    public function getRequiredPermission(): ?string { return NickServPermission::IDENTIFIED_OWNER; }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'PASSWORD', 'desc_key' => 'set.password.short', 'help_key' => 'set.password.help', 'syntax_key' => 'set.password.syntax'],
            ['name' => 'EMAIL', 'desc_key' => 'set.email.short', 'help_key' => 'set.email.help', 'syntax_key' => 'set.email.syntax'],
        ];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->senderAccount) {
            $context->reply('error.not_identified');
            return;
        }

        $option = strtoupper($context->args[0]);
        $handler = $this->handlers[$option] ?? null;

        if (null === $handler) {
            $context->reply('set.unknown_option', ['%option%' => $option]);
            return;
        }

        $handler->handle($context, $context->senderAccount, $context->args[1] ?? '');
    }
}
```

Sub-command handlers are registered as regular services (no command tag).

## DI Registration

```yaml
# config/services.yaml

App\Application\NickServ\Command\Handler\MyCommand:
    tags: ['nickserv.command']

App\Application\NickServ\Command\Handler\RegisterCommand:
    arguments:
        $registerMinIntervalSeconds: '%nickserv.register_min_interval%'
    tags: ['nickserv.command']
```

### Service Tags

| Service | Tag |
|---------|-----|
| NickServ | `nickserv.command` |
| ChanServ | `chanserv.command` |
| MemoServ | `memoserv.command` |
| OperServ | `operserv.command` |

## Command Checklist

When creating a new command, complete ALL these steps:

1. **Command Handler class** — implement `{Service}CommandInterface`
2. **Translations** — add keys to ALL 14 language files for the service
3. **DI Registration** — add service with appropriate tag in `config/services.yaml`
4. **Permissions** — configure `getRequiredPermission()` (see `.agents/services/commands-permissions.md`)
5. **Tests** — 100% coverage (see `.agents/services/commands-testing.md`)

## Context Reference

### Common (all services via `IrcopContextInterface`)

```php
public function reply(string $key, array $params = []): void;
public function replyRaw(string $message): void;
public function trans(string $key, array $params = []): string;
public function transIn(string $key, array $params = [], string $language = ''): string;
public function getLanguage(): string;
public function getTimezone(): string;
public function formatDate(?DateTimeInterface $date): string;
```

### NickServContext

Access to `$context->sender`, `$context->senderAccount`, `$context->args`, `$context->command`. Additional: `getPendingVerificationRegistry()`, `getRecoveryTokenRegistry()`.

### ChanServContext

Access to `$context->getChannelNameArg(int)`, `$context->getChannelView(string)`, `$context->getNotifier()`.

### MemoServContext

Access to standard methods + `$context->getNotifier()`.

### OperServContext

Access to standard methods + `$context->isRoot()`, `$context->getBotName()`, `$context->getAccessHelper()`.

### SenderView

```php
readonly class SenderView
{
    public function __construct(
        public string $uid,
        public string $nick,
        public string $ident,
        public string $hostname,
        public string $cloakedHost,
        public string $ipBase64,
        public bool $isIdentified = false,
        public bool $isOper = false,
        public string $serverSid = '',
        public string $displayHost = '',
        public string $modes = '',
    ) {}
}
```

## Service-Specific Patterns

### NickServ

- Check identification: `if (!$context->sender->isIdentified)` → `reply('error.not_identified')`
- Get account: `if (null === $context->senderAccount)` → `reply('error.not_identified')`
- Throttling: use `RegisterThrottleRegistry` with `isThrottled()` + `recordAttempt()`

### ChanServ

- Channel argument: `$context->getChannelNameArg(0)` returns `#channel` or null
- Access check: `ChanServAccessHelper::getAccessLevel($nickId, $channelName)`

### MemoServ

- Recipient validation: find nick via repository, check `isActive()`

### OperServ

- IRCop check: `if (!$context->sender->isOper)` → `reply('error.ircop_only')`
- Root check: `if (!$context->isRoot())` → `reply('error.root_only')`

## Related Skills

- `.agents/services/commands-permissions.md` — Authorization, voters, permission sync
- `.agents/services/commands-translations.md` — i18n YAML structure, IRC colors, 14-language rule
- `.agents/services/commands-testing.md` — Test patterns for command handlers
- `.agents/services/help-design.md` — HELP command format
