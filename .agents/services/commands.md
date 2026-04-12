# Commands - Creating New IRC Service Commands

This document describes how to create new commands for IRC services (NickServ, ChanServ, MemoServ, OperServ).

## Overview

Each service handles user commands through a dispatcher that:

1. Parses the raw text from PRIVMSG
2. Finds the matching command handler in the registry
3. Checks authorization via `getRequiredPermission()`
4. Validates minimum arguments
5. Executes the command with a Context object

### Authorization Architecture

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
               Granted? ─── NO ──▶ Reply: error.permission_denied
                      │
                     YES
                      │
                      ▼
          Check argument count (getMinArgs)
                      │
                      ▼
               handler->execute($context)
```

## Checklist

When creating a new command, complete ALL these steps:

- [ ] **Step 1**: Create the Command Handler class
- [ ] **Step 2**: Add translations (en + es)
- [ ] **Step 3**: Register in `config/services.yaml`
- [ ] **Step 4**: Configure permissions (if needed)
- [ ] **Step 5**: Create tests with 100% coverage
- [ ] **Step 6**: Update documentation (if needed)

---

## Parallel Implementation Checklist (CRITICAL — PERFORMANCE)

**Execute these tasks IN PARALLEL whenever possible to speed up implementation.**

### Phase 1: Exploration (Parallel Reads - SINGLE MESSAGE)

Launch ALL these reads in ONE message:

- [ ] `grep "CommandInterface" src/Application/` → Find existing command patterns
- [ ] `glob "src/Application/*/Command/Handler/*Command.php"` → See command structure
- [ ] `glob "translations/*.yaml"` → Check translation file locations
- [ ] `glob "tests/Application/**/*Test.php"` → Check test patterns
- [ ] `read config/services.yaml` → Check service tag patterns

### Phase 2: Creation (Parallel Writes - SINGLE MESSAGE)

Write ALL files in ONE message after Phase 1 completes:

```php
// Message with 5-6 parallel writes:
write src/Domain/Service/Entity/NewEntity.php           // If needed
write src/Application/Service/Command/Handler/NewCommand.php
write tests/Application/Service/Command/Handler/NewCommandTest.php
edit translations/service.en.yaml   // append translations
edit translations/service.es.yaml   // append translations
```

### Phase 3: Configuration (Sequential - MUST WAIT)

After Phase 2, SEQUENTIALLY:

1. [ ] Add service tag to `config/services.yaml`
2. [ ] Add permission constant (if IRCop command) in `src/Application/Service/Security/ServiceIrcopPermission.php`
3. [ ] Add permission to IrcopPermission class (if IRCop command)

### Phase 4: Verification (Parallel - SINGLE MESSAGE)

Run ALL in parallel:

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php && \
./vendor/bin/phpunit tests/Application/Service --no-coverage --display-all-issues && \
./scripts/check-coverage.sh 100
```

### Parallelization Speedup

| Approach | Time |
|----------|------|
| Sequential (old) | ~15-20 min |
| Parallel (new) | ~5-8 min |

Speedup: **3-4x faster** for new commands.

---

## Step 1: Create the Command Handler

### Location

```
src/Application/{Service}/Command/Handler/{CommandName}.php
```

Example: `src/Application/NickServ/Command/Handler/RegisterCommand.php`

### Interface

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
    public function isOperOnly(): bool;          // IRC operator only? (deprecated)
    public function getRequiredPermission(): ?string;  // Permission attribute or null
    public function execute(NickServContext $context): void;
}
```

### Basic Command Template

**NickServ / MemoServ / OperServ** — use `NickServCommandInterface`, `MemoServCommandInterface`, or `OperServCommandInterface`. These only have the standard methods shown above.

**ChanServ** — uses `ChanServCommandInterface` which adds two extra methods for channel status checks:

```php
interface ChanServCommandInterface
{
    // ... all standard methods from above ...

    /**
     * Whether this command is allowed on suspended channels.
     * Commands like SUSPEND, UNSUSPEND, INFO, and DROP should return true.
     */
    public function allowsSuspendedChannel(): bool;

    /**
     * Whether this command is allowed on forbidden channels.
     * ONLY FORBID (update reason), UNFORBID, and INFO should return true.
     * ALL other commands MUST return false — even for IRCops with level_founder.
     */
    public function allowsForbiddenChannel(): bool;
}
```

#### Forbidden Channel Rules (CRITICAL)

A forbidden channel is NOT a registered channel — it's a block on a channel name. The `allowsForbiddenChannel()` check is enforced in `ChanServService::dispatch()` **before** any handler runs, and it **cannot be bypassed** — not even by `isLevelFounder`.

- `allowsForbiddenChannel() = true`: **ONLY** FORBID, UNFORBID, and INFO
- `allowsForbiddenChannel() = false`: ALL other commands (SET, ACCESS, AKICK, OP, DROP, SUSPEND, etc.)

When creating a new ChanServ command, you MUST implement `allowsForbiddenChannel()`. Return `false` by default.

#### Suspended Channel Rules

A suspended channel is still registered but frozen. Commands that manage suspensions need access:

- `allowsSuspendedChannel() = true`: SUSPEND, UNSUSPEND, DROP, INFO, FORBID, UNFORBID, HELP
- `allowsSuspendedChannel() = false`: All other commands (SET, ACCESS, AKICK, OP, etc.)

When `isLevelFounder` is true, the suspended channel check is bypassed — the IRCop can use any command on a suspended channel.

### Basic Command Template

```php
<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;

final readonly class MyCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly SomeRepositoryInterface $repository,
        private readonly int $someConfigurableParam,  // From services.yaml
    ) {
    }

    public function getName(): string
    {
        return 'MYCOMMAND';
    }

    public function getAliases(): array
    {
        return ['MC'];  // Alternative names
    }

    public function getMinArgs(): int
    {
        return 2;  // /msg NickServ MYCOMMAND <arg1> <arg2>
    }

    public function getSyntaxKey(): string
    {
        return 'mycommand.syntax';
    }

    public function getHelpKey(): string
    {
        return 'mycommand.help';
    }

    public function getOrder(): int
    {
        return 10;  // Lower = appears first in HELP
    }

    public function getShortDescKey(): string
    {
        return 'mycommand.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];  // Only for commands like SET, ACCESS
    }

    public function isOperOnly(): bool
    {
        return false;  // Deprecated - use getRequiredPermission() instead
    }

    public function getRequiredPermission(): ?string
    {
        return null;  // No permission required (public command)
    }

    public function execute(NickServContext $context): void
    {
        // 1. Check if sender exists
        if (null === $context->sender) {
            return;
        }

        // 2. Get arguments
        $arg1 = $context->args[0];
        $arg2 = $context->args[1];

        // 3. Business logic
        // ...

        // 4. Reply to user
        $context->reply('mycommand.done', ['%arg1%' => $arg1]);
    }
}
```

### Command with Sub-Options (SET-style)

For commands like SET, ACCESS, use a handler map:

```php
final readonly class SetCommand implements NickServCommandInterface
{
    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    public function __construct(
        SetPasswordHandler $setPasswordHandler,
        SetEmailHandler $setEmailHandler,
        SetLanguageHandler $setLanguageHandler,
    ) {
        $this->handlers = [
            'PASSWORD' => $setPasswordHandler,
            'EMAIL' => $setEmailHandler,
            'LANGUAGE' => $setLanguageHandler,
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
            ['name' => 'LANGUAGE', 'desc_key' => 'set.language.short', 'help_key' => 'set.language.help', 'syntax_key' => 'set.language.syntax'],
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

---

## Step 2: Add Translations

### File Locations

```
translations/nickserv.en.yaml
translations/nickserv.es.yaml
translations/chanserv.en.yaml
translations/chanserv.es.yaml
translations/memoserv.en.yaml
translations/memoserv.es.yaml
translations/operserv.en.yaml
translations/operserv.es.yaml
```

### Translation Structure

```yaml
# Command metadata
mycommand:
  syntax: "MYCOMMAND <arg1> <arg2>"
  short: "Short description shown in HELP list."
  help: |
    Full help text that can span
    multiple lines.

# Command messages
mycommand:
  done: "\x0303✓\x03 Operation completed for %arg1%."
  error_invalid: "\x0304✗\x03 Invalid argument: %arg1%"
  not_found: "\x0304✗\x03 %arg1% not found."

# Sub-commands (for SET, ACCESS, etc.)
mycommand:
  subcommand:
    syntax: "MYCOMMAND SUB <arg>"
    short: "Sub-command description."
    help: "Detailed help..."
```

### IRC Color Codes

Use these in translations:

| Code | Effect | Example |
|------|--------|---------|
| `\x02` | Bold on/off | `\x02bold\x02` |
| `\x0302` | Blue | `\x0302text\x03` |
| `\x0303` | Green | `\x0303success\x03` |
| `\x0304` | Red | `\x0304error\x03` |
| `\x0307` | Orange | `\x0307warning\x03` |
| `\x0310` | Cyan | `\x0310info\x03` |
| `\x0314` | Dark grey | `\x0314muted\x03` |
| `\x03` | Reset color | End of colored text |
| `\x0F` | Reset all formatting | End of bold + color |

Example:

```yaml
register:
  success: "\x0303✓\x03 Nickname \x02%nickname%\x02 has been successfully registered."
  pending: "\x0307→\x03 A verification token has been sent to \x02%email%\x02."
  error: "\x0304✗\x03 Registration failed: %reason%"
```

### Permission Translations

For commands with IRCop permissions, add descriptions in `translations/{service}.en.yaml`:

```yaml
# File: translations/nickserv.en.yaml
permissions:
  nickserv.drop: "Drop a registered nickname"
  nickserv.info: "View extended info of any nickname"
  nickserv.sendpass: "Send password reset email"
```

```yaml
# File: translations/nickserv.es.yaml
permissions:
  nickserv.drop: "Eliminar un nickname registrado"
  nickserv.info: "Ver información extendida de cualquier nickname"
  nickserv.sendpass: "Enviar email de reseteo de contraseña"
```

These descriptions appear in `ROLE PERMS <role> LIST`:

```
-OperServ- --- Permissions for ADMIN ---
-OperServ- Assigned:
-OperServ-   nickserv.drop - Drop a registered nickname
-OperServ- Available:
-OperServ-   nickserv.info - View extended info of any nickname
```

### Automatic Placeholders

The `%bot%` placeholder is automatically injected with the service nickname:

```yaml
# No need to pass %bot% manually
welcome: "Welcome to %bot%! Type /msg %bot% HELP for commands."
```

---

## Step 3: Register in services.yaml

### Basic Registration

```yaml
# config/services.yaml

# Simple registration with autowire
App\Application\NickServ\Command\Handler\MyCommand:
    tags: ['nickserv.command']

# With configurable parameters
App\Application\NickServ\Command\Handler\RegisterCommand:
    arguments:
        $registerMinIntervalSeconds: '%nickserv.register_min_interval%'
    tags: ['nickserv.command']
```

### Service Tags by Service

| Service | Tag |
|---------|-----|
| NickServ | `nickserv.command` |
| ChanServ | `chanserv.command` |
| MemoServ | `memoserv.command` |
| OperServ | `operserv.command` |

### Sub-Command Handlers

For SET-style commands, handlers are registered as regular services (no tag):

```yaml
# Sub-command handlers (not commands themselves)
App\Application\NickServ\Command\Handler\SetPasswordHandler:
    arguments:
        $passwordHasher: '@App\Domain\NickServ\Service\PasswordHasherInterface'

App\Application\NickServ\Command\Handler\SetEmailHandler: ~

# Main SET command (has tag)
App\Application\NickServ\Command\Handler\SetCommand:
    arguments:
        $setPasswordHandler: '@App\Application\NickServ\Command\Handler\SetPasswordHandler'
        $setEmailHandler: '@App\Application\NickServ\Command\Handler\SetEmailHandler'
    tags: ['nickserv.command']
```

---

## Step 4: Configure Permissions

### Permission Types

| Type | Return Value | Checked By | Example Command |
|------|--------------|------------|-----------------|
| Public (no auth) | `null` | None | HELP, REGISTER |
| Identified | `'IDENTIFIED'` | `IdentifiedVoter` | INFO (extended) |
| Owner | `NickServPermission::IDENTIFIED_OWNER` | `NickServIdentifiedOwnerVoter` | SET, DROP |
| IRCop | `OperServPermission::KILL` | `IrcopPermissionVoter` | KILL, IRCOP ADD |

### No Permission Required

```php
public function getRequiredPermission(): ?string
{
    return null;  // Anyone can use
}
```

### IDENTIFIED Permission

```php
public function getRequiredPermission(): ?string
{
    return 'IDENTIFIED';  // User must be identified (+r mode)
}
```

The service automatically replies with `error.not_identified` if the user is not identified.

### Owner Permission

```php
// File: src/Application/NickServ/Security/NickServPermission.php
final readonly class NickServPermission
{
    public const string IDENTIFIED_OWNER = 'nickserv_identified_owner';
}

// File: src/Application/NickServ/Command/Handler/SetCommand.php
public function getRequiredPermission(): ?string
{
    return NickServPermission::IDENTIFIED_OWNER;
}
```

### IRCop Permission

```php
// File: src/Application/OperServ/Security/OperServPermission.php
final class OperServPermission
{
    public const string KILL = 'operserv.kill';
}

// File: src/Application/OperServ/Security/OperServIrcopPermission.php
final readonly class OperServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'OperServ';
    }

    public function getPermissions(): array
    {
        return [
            OperServPermission::KILL,
        ];
    }
}

// File: src/Application/OperServ/Command/Handler/KillCommand.php
public function getRequiredPermission(): ?string
{
    return OperServPermission::KILL;
}
```

### Root-Only Command

For commands that only root users can execute:

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

    // Root-only logic here
}
```

### Authorization Flow in Detail

The authorization check happens in `{Service}Service::dispatch()`:

```php
// File: src/Application/NickServ/NickServService.php
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

### Synchronizing Permission Classes

**CRITICAL**: These files must be kept synchronized:

1. **`{Service}Permission.php`** - Constants used by Voters
2. **`{Service}IrcopPermission.php`** - PermissionProviderInterface implementation
3. **`translations/{service}.en.yaml`** - Permission descriptions

When adding a new IRCop permission:

```php
// 1. Add constant in XxxPermission.php
final class NickServPermission
{
    public const string DROP = 'nickserv.drop';
}

// 2. Add to XxxIrcopPermission.php
public function getPermissions(): array
{
    return [
        NickServPermission::DROP,
    ];
}

// 3. Add translation in translations/nickserv.en.yaml
permissions:
  nickserv.drop: "Drop a registered nickname"
```

### Creating a Custom Voter

If the permission requires custom logic beyond the standard voters:

```php
// File: src/Infrastructure/NickServ/Security/Voter/NickServIdentifiedOwnerVoter.php
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

## Step 5: Create Tests

### Test File Location

```
tests/Application/{Service}/Command/Handler/{CommandName}Test.php
```

### Test Class Structure

```php
<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\MyCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MyCommand::class)]
final class MyCommandTest extends TestCase
{
    // Test all interface methods
    #[Test]
    public function getNameReturnsCorrectName(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('MYCOMMAND', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsCorrectAliases(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(['MC'], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsCorrectNumber(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('mycommand.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('mycommand.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsCorrectOrder(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(10, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('mycommand.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = $this->createCommand();

        self::assertNull($cmd->getRequiredPermission());
    }

    // Test execution paths
    #[Test]
    public function executeWithValidArgsSucceeds(): void
    {
        // ... test success path
    }

    #[Test]
    public function executeWithMissingArgsRepliesSyntaxError(): void
    {
        // ... test error path
    }

    // Helper methods
    private function createCommand(): MyCommand
    {
        return new MyCommand(
            $this->createStub(SomeRepositoryInterface::class),
            3600,  // configurable parameter
        );
    }

    private function createContext(
        SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,  // senderAccount
            'MYCOMMAND',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        // Standard pattern for service nickname registry
    }
}
```

### Using Mock vs Stub

**Use `createStub()` when you only need to provide return values:**

```php
$nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
$nickRepo->method('findByNick')->willReturn($existing);
```

**Use `createMock()` with `expects()` when verifying method calls:**

```php
$nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
$nickRepo->expects(self::once())
    ->method('save')
    ->with(self::callback(function (RegisteredNick $n): bool {
        return 'NewNick' === $n->getNickname();
    }));
```

### Testing Authorization

```php
#[Test]
public function executeWhenNotIdentifiedRepliesNotIdentified(): void
{
    $sender = new SenderView(
        uid: 'UID1',
        nick: 'User',
        ident: 'i',
        hostname: 'h',
        cloakedHost: 'c',
        ipBase64: 'ip',
        isIdentified: false,  // Not identified
        isOper: false,
        serverSid: 'SID',
        displayHost: 'h',
        modes: 'i',
    );

    // ... create context with not-identified sender
    // ... execute command
    // ... assert that 'error.not_identified' was sent
}

#[Test]
public function executeWhenIdentifiedButNotOwnerRepliesPermissionDenied(): void
{
    $sender = new SenderView(
        uid: 'UID1',
        nick: 'User',  // Different from account
        ident: 'i',
        hostname: 'h',
        cloakedHost: 'c',
        ipBase64: 'ip',
        isIdentified: true,
        isOper: false,
        serverSid: 'SID',
        displayHost: 'h',
        modes: 'i',
    );

    $account = $this->createStub(RegisteredNick::class);
    $account->method('getNickname')->willReturn('OtherNick');

    // ... test owner permission check
}
```

### Testing IRCop Permissions

```php
#[Test]
public function getRequiredPermissionReturnsKillPermission(): void
{
    $cmd = new KillCommand(/* ... */);

    self::assertSame(OperServPermission::KILL, $cmd->getRequiredPermission());
}

#[Test]
public function targetIsIrcopGetsProtectedIrcopError(): void
{
    $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    $target = new SenderView('UID2', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');

    // Setup IRCop target
    $ircop = OperIrcop::create(42, $role, 1, null);
    $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
    $ircopRepo->method('findByNickId')->willReturn($ircop);

    // ... execute command
    // ... assert 'kill.protected_ircop' was sent
}

#[Test]
public function rootUserCannotBeKilled(): void
{
    $rootRegistry = new RootUserRegistry('RootUser');
    // ... test that root users are protected
}
```

### Coverage Requirements

1. **Every public method must have at least one test**
2. **Every branch (if/else) must be tested**
3. **Every early return must be tested**
4. **Edge cases must be tested** (null values, empty arrays, boundary values)

Run coverage check:

```bash
./scripts/check-coverage.sh 100
```

Find uncovered lines:

```bash
grep 'count="0"' var/coverage/clover.xml
```

---

## Context Reference

### Common Methods (all services)

```php
interface IrcopContextInterface
{
    public function getSender(): ?SenderView;
    public function getSenderAccount(): ?RegisteredNick;
    public function reply(string $key, array $params = []): void;
    public function replyRaw(string $message): void;
    public function trans(string $key, array $params = []): string;
    public function getLanguage(): string;
    public function getTimezone(): string;
    public function formatDate(?DateTimeInterface $date): string;
}
```

### NickServContext

```php
final readonly class NickServContext implements IrcopContextInterface
{
    public readonly ?SenderView $sender;
    public readonly ?RegisteredNick $senderAccount;
    public readonly string $command;
    public readonly array $args;

    public function reply(string $key, array $params = []): void;
    public function replyRaw(string $message): void;
    public function trans(string $key, array $params = []): string;
    public function transIn(string $key, array $params = [], string $language = ''): string;
    public function formatDate(?DateTimeInterface $date): string;

    public function getNotifier(): NickServNotifierInterface;
    public function getRegistry(): NickServCommandRegistry;
    public function getPendingVerificationRegistry(): PendingVerificationRegistry;
    public function getRecoveryTokenRegistry(): RecoveryTokenRegistry;
}
```

### ChanServContext

```php
final readonly class ChanServContext implements IrcopContextInterface
{
    // ... common methods ...

    public function getChannelNameArg(int $index = 0): ?string;  // Returns #channel or null
    public function getChannelView(string $channelName): ?ChannelView;  // Live channel state
    public function getNotifier(): ChanServNotifierInterface;
}
```

### MemoServContext

```php
final readonly class MemoServContext implements IrcopContextInterface
{
    // ... common methods ...

    public function getNotifier(): MemoServNotifierInterface;
}
```

### OperServContext

```php
final readonly class OperServContext implements IrcopContextInterface
{
    // ... common methods ...

    public function isRoot(): bool;  // Check if sender is configured root
    public function getBotName(): string;
    public function getAccessHelper(): IrcopAccessHelper;
}
```

### SenderView Properties

```php
readonly class SenderView
{
    public function __construct(
        public readonly string $uid,
        public readonly string $nick,
        public readonly string $ident,
        public readonly string $hostname,
        public readonly string $cloakedHost,
        public readonly string $ipBase64,
        public readonly bool $isIdentified = false,
        public readonly bool $isOper = false,
        public readonly string $serverSid = '',
        public readonly string $displayHost = '',
        public readonly string $modes = '',
    ) {}
}
```

---

## Common Patterns by Service

### NickServ Patterns

#### Checking Identification

```php
public function execute(NickServContext $context): void
{
    if (null === $context->sender) {
        return;
    }

    if (!$context->sender->isIdentified) {
        $context->reply('error.not_identified');

        return;
    }

    // User is identified...
}
```

#### Getting User Account

```php
public function execute(NickServContext $context): void
{
    if (null === $context->senderAccount) {
        $context->reply('error.not_identified');

        return;
    }

    $account = $context->senderAccount;
    $nickname = $account->getNickname();
}
```

#### Throttling Actions

```php
public function __construct(
    private readonly RegisterThrottleRegistry $throttle,
    private readonly int $minIntervalSeconds,
) {
}

public function execute(NickServContext $context): void
{
    $ipHash = 'ip:' . $context->sender->ipBase64;

    if ($this->throttle->isThrottled($ipHash, $this->minIntervalSeconds)) {
        $context->reply('register.throttled');

        return;
    }

    $this->throttle->recordAttempt($ipHash);
    // ... continue with registration
}
```

### ChanServ Patterns

#### Getting Channel Argument

```php
public function execute(ChanServContext $context): void
{
    $channelName = $context->getChannelNameArg(0);

    if (null === $channelName) {
        $context->reply('error.invalid_channel');

        return;
    }

    $channelView = $context->getChannelView($channelName);

    if (null === $channelView) {
        $context->reply('error.channel_not_registered');

        return;
    }

    // Use $channelView->getModes(), $channelView->getTopic(), etc.
}
```

#### Checking Channel Access

```php
public function __construct(
    private readonly ChanServAccessHelper $accessHelper,
    // ...
) {
}

public function execute(ChanServContext $context): void
{
    $channelName = $context->getChannelNameArg(0);

    if (!$context->senderAccount) {
        $context->reply('error.not_identified');
        return;
    }

    $accessLevel = $this->accessHelper->getAccessLevel(
        $context->senderAccount->getId(),
        $channelName
    );

    if ($accessLevel < 10) {  // AOP minimum
        $context->reply('error.access_denied');
        return;
    }

    // User has sufficient access...
}
```

### MemoServ Patterns

#### Validating Recipient

```php
public function execute(MemoServContext $context): void
{
    $recipientNick = $context->args[0];

    $recipient = $this->nickRepo->findByNick($recipientNick);

    if (null === $recipient) {
        $context->reply('send.nick_not_registered', ['%nick%' => $recipientNick]);

        return;
    }

    if (!$recipient->isActive()) {
        $context->reply('send.nick_not_active', ['%nick%' => $recipientNick]);

        return;
    }

    // Continue with send...
}
```

### OperServ Patterns

#### Checking IRCop Status

```php
public function execute(OperServContext $context): void
{
    if (null === $context->sender) {
        return;
    }

    if (!$context->sender->isOper) {
        $context->reply('error.ircop_only');

        return;
    }

    // User is IRC operator...
}
```

#### Root-Only Command

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

    // Root-only logic...
}
```

#### Using Debug Actions

For sensitive commands (KILL, IRCOP ADD, etc.), log to debug channel:

```php
public function __construct(
    private readonly DebugActionPort $debug,
    // ...
) {
}

public function execute(OperServContext $context): void
{
    // ... perform action ...

    $this->debug->log(
        operator: $context->sender->nick,
        command: 'KILL',
        target: $targetNick,
        targetHost: $target->ident . '@' . $target->hostname,
        targetIp: $target->ipBase64,
        reason: $reason,
    );
}
```

See `.agents/services/debug-actions.md` for full details.

---

## Debug Actions (for IRCop Commands)

Sensitive commands executed by IRCops should log actions to:

1. **File**: `var/log/ircops.log` (always active)
2. **Channel**: Configured via `IRCOPS_DEBUG_CHANNEL` env var

### When to Use Debug Actions

- KILL - Disconnecting users
- IRCOP ADD/DEL - Managing IRC operators
- GLINE/KLINE - Network-wide bans
- Channel closures/suspensions

### Implementation

1. **Add translation keys** in `translations/operserv.en.yaml`:

```yaml
debug:
  action_message: "%operator% executes command %command% on %target%. Reason: %reason%"
  action_info: "Nick: %nick% | Host: %host% | IP: %ip%"
```

2. **Inject DebugActionPort** in your command:

```php
public function __construct(
    private readonly DebugActionPort $debug,
    // ... other dependencies
) {
}
```

3. **Log the action**:

```php
$this->debug->log(
    operator: $operatorNick,
    command: 'KILL',
    target: $targetNick,
    targetHost: $target->ident . '@' . $target->hostname,
    targetIp: $target->ipBase64,
    reason: $reason,
);
```

4. **Configure in services.yaml**:

```yaml
App\Infrastructure\OperServ\Service\OperServDebugAction:
    arguments:
        $chanservNick: '%chanserv.nick%'
        $debugChannel: '%ircops.debug_channel%'
        $logger: '@monolog.logger.ircops'
```

---

## Common Mistakes and Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| Command doesn't appear in HELP | Missing tag in services.yaml | Add `tags: ['nickserv.command']` |
| Permission not available in ROLE PERMS LIST | IrcopPermission not synchronized | Add to both `XxxPermission.php` and `XxxIrcopPermission.php` |
| Translation not found | Wrong key format | Use `{command}.{subkey}` pattern |
| `count="0"` in coverage | Untested branch | Add test for each `if`/`else` |
| Dependency injection error | Interface not bound | Add binding in `services.yaml` |
| Null in execute() | `$context->sender` is null | Always check `if (null === $context->sender)` |
| Authorization silent failure | Voter missing or wrong attribute | Check `supports()` method in Voter |
| Wrong number of translations | Missing file | Add translations in both `.en.yaml` and `.es.yaml` |
| Coverage < 100% after changes | New code, no tests | Run `./scripts/check-coverage.sh 100` |
| Service circular reference | Constructor injection loop | Use setter injection or refactor |
| Context not constructed properly | Missing/test args wrong | Copy context creation from existing tests |

---

## Complete Example: NickServ DROP (Future IRCop Command)

This example shows how to add an IRCop-only command to NickServ.

### Step 1: Add Permission Constants

```php
// File: src/Application/NickServ/Security/NickServPermission.php
final readonly class NickServPermission
{
    public const string IDENTIFIED_OWNER = 'nickserv_identified_owner';
    public const string DROP = 'nickserv.drop';  // NEW

    private function __construct()
    {
    }
}
```

### Step 2: Update Permission Provider

```php
// File: src/Application/NickServ/Security/NickServIrcopPermission.php
final readonly class NickServIrcopPermission implements PermissionProviderInterface
{
    public function getServiceName(): string
    {
        return 'NickServ';
    }

    public function getPermissions(): array
    {
        return [
            NickServPermission::DROP,  // NEW
        ];
    }
}
```

### Step 3: Create Command Handler

```php
// File: src/Application/NickServ/Command/Handler/DropCommand.php
<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class DropCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'DROP';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;  // DROP <nickname>
    }

    public function getSyntaxKey(): string
    {
        return 'drop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'drop.help';
    }

    public function getOrder(): int
    {
        return 50;
    }

    public function getShortDescKey(): string
    {
        return 'drop.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;  // Use getRequiredPermission() instead
    }

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::DROP;
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];

        $registered = $this->nickRepository->findByNick($targetNick);
        if (null === $registered) {
            $context->reply('drop.not_registered', ['%nick%' => $targetNick]);

            return;
        }

        // Check if user is online with this nick
        $onlineUser = $this->userLookup->findByNick($targetNick);
        if (null !== $onlineUser) {
            $context->reply('drop.nick_online', ['%nick%' => $targetNick]);

            return;
        }

        // Drop the nickname
        $this->nickRepository->delete($registered);

        $this->logger->info('Nickname dropped via DROP command', [
            'nickname' => $targetNick,
            'dropped_by' => $context->sender->nick,
        ]);

        $context->reply('drop.done', ['%nick%' => $targetNick]);
    }
}
```

### Step 4: Add Translations

```yaml
# File: translations/nickserv.en.yaml
drop:
  syntax: "DROP <nickname>"
  short: "Drop a registered nickname"
  help: |
    Drop a registered nickname from the database.
    This action cannot be undone.
    
    This command requires IRCop permission: nickserv.drop
    
    Syntax: DROP <nickname>
    Example: DROP OldAccount
  not_registered: "\x0304✗\x03 Nickname \x02%nick%\x02 is not registered."
  nick_online: "\x0304✗\x03 Nickname \x02%nick%\x02 is currently in use. Force the user off first."
  done: "\x0303✓\x03 Nickname \x02%nick%\x02 has been dropped."

permissions:
  nickserv.drop: "Drop a registered nickname"
```

```yaml
# File: translations/nickserv.es.yaml
drop:
  syntax: "DROP <nickname>"
  short: "Eliminar un nickname registrado"
  help: |
    Elimina un nickname registrado de la base de datos.
    Esta acción no se puede deshacer.
    
    Este comando requiere permiso de IRCop: nickserv.drop
    
    Sintaxis: DROP <nickname>
    Ejemplo: DROP OldAccount
  not_registered: "\x0304✗\x03 El nickname \x02%nick%\x02 no está registrado."
  nick_online: "\x0304✗\x03 El nickname \x02%nick%\x02 está actualmente en uso. Fuerza al usuario a desconectarse primero."
  done: "\x0303✓\x03 El nickname \x02%nick%\x02 ha sido eliminado."

permissions:
  nickserv.drop: "Eliminar un nickname registrado"
```

### Step 5: Register in services.yaml

```yaml
# File: config/services.yaml
App\Application\NickServ\Command\Handler\DropCommand:
    arguments:
        $nickRepository: '@App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface'
        $userLookup: '@App\Application\Port\NetworkUserLookupPort'
        $logger: '@monolog.logger.nickserv'
    tags: ['nickserv.command']
```

### Step 6: Create Tests

```php
// File: tests/Application/NickServ/Command/Handler/DropCommandTest.php
<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\DropCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DropCommand::class)]
final class DropCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsDrop(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('DROP', $cmd->getName());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getRequiredPermissionReturnsDrop(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::DROP, $cmd->getRequiredPermission());
    }

    #[Test]
    public function dropUnregisteredNickRepliesNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $cmd = new DropCommand($nickRepo, $userLookup, $this->createStub(LoggerInterface::class));
        $context = $this->createContext($sender, ['UnknownNick'], $notifier, $translator);

        $cmd->execute($context);

        self::assertStringContainsString('drop.not_registered', $messages[0]);
    }

    // ... more tests for 100% coverage

    private function createCommand(): DropCommand
    {
        return new DropCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function createContext(
        SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            sender: $sender,
            senderAccount: null,
            command: 'DROP',
            args: $args,
            notifier: $notifier,
            translator: $translator,
            language: 'en',
            timezone: 'UTC',
            messageType: 'NOTICE',
            registry: new NickServCommandRegistry([]),
            pendingVerificationRegistry: new \App\Application\NickServ\PendingVerificationRegistry(),
            recoveryTokenRegistry: new \App\Application\NickServ\RecoveryTokenRegistry(),
            serviceNicks: $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): \App\Application\ApplicationPort\ServiceNicknameRegistry
    {
        // ... standard implementation
    }
}
```

### Step 7: Run Tests and Coverage

```bash
# Run tests
./vendor/bin/phpunit tests/Application/NickServ/Command/Handler/DropCommandTest.php --no-coverage

# Check coverage
./scripts/check-coverage.sh 100
```

---

## Related Documentation

- **Core vs Services Architecture**: `.agents/services/README.md`
- **IRCop Permission System**: `.agents/services/ircop-commands.md`
- **Debug Actions**: `.agents/services/debug-actions.md`
- **Testing Coverage Priorities**: `.agents/testing/testing-coverage-priorities.md`