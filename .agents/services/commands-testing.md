# Commands — Testing Patterns

Use this skill when writing tests for command handlers.

---

## Test File Location

```
tests/Application/{Service}/Command/Handler/{CommandName}Test.php
```

---

## Test Class Structure

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
    // Interface method tests
    // Execution path tests
    // Helper methods
}
```

---

## Required Tests for Every Command

### Interface Methods (mandatory — one test each)

```php
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
public function getRequiredPermissionReturnsCorrectValue(): void
{
    $cmd = $this->createCommand();
    self::assertNull($cmd->getRequiredPermission());
}
```

### Execution Paths (must cover ALL branches)

```php
#[Test]
public function executeWithNullSenderReturnsEarly(): void
{
    $context = $this->createContext(sender: null, args: ['arg1']);
    $cmd = $this->createCommand();
    $cmd->execute($context);
    self::assertEmpty($this->messages);  // No messages sent
}

#[Test]
public function executeWithValidArgsSendsSuccessMessage(): void
{
    $sender = $this->createSender(nick: 'User', isIdentified: true);
    $context = $this->createContext(sender: $sender, args: ['arg1', 'arg2']);
    $cmd = $this->createCommand();
    $cmd->execute($context);
    self::assertStringContainsString('done', $this->messages[0]);
}

#[Test]
public function executeWhenNotIdentifiedRepliesError(): void
{
    $sender = $this->createSender(nick: 'User', isIdentified: false);
    $context = $this->createContext(sender: $sender, args: ['arg1']);
    $cmd = $this->createCommand();
    $cmd->execute($context);
    self::assertStringContainsString('error.not_identified', $this->messages[0]);
}
```

---

## Using Stubs vs Mocks

### createStub() — For dependencies that only provide values

```php
$nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
$nickRepo->method('findByNick')->willReturn($existingNick);
```

### createMock() with expects() — For verifying method calls

```php
$nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
$nickRepo->expects(self::once())
    ->method('save')
    ->with(self::callback(function (RegisteredNick $n): bool {
        return 'NewNick' === $n->getNickname();
    }));
```

**Never** use `createMock()` without `expects()` — triggers PHPUnit notice.

---

## Context Factories

### NickServContext

```php
private function createNickServContext(
    ?SenderView $sender = null,
    array $args = [],
    ?RegisteredNick $senderAccount = null,
): NickServContext {
    return new NickServContext(
        sender: $sender,
        senderAccount: $senderAccount,
        command: 'MYCOMMAND',
        args: $args,
        notifier: $this->notifier,
        translator: $this->translator,
        language: 'en',
        timezone: 'UTC',
        messageType: 'NOTICE',
        registry: new NickServCommandRegistry([]),
        pendingVerificationRegistry: new PendingVerificationRegistry(),
        recoveryTokenRegistry: new RecoveryTokenRegistry(),
        serviceNicks: $this->createServiceNicks(),
    );
}
```

### ChanServContext

```php
private function createChanServContext(
    ?SenderView $sender = null,
    array $args = [],
): ChanServContext {
    return new ChanServContext(
        sender: $sender,
        senderAccount: null,
        command: 'MYCOMMAND',
        args: $args,
        // ... chanServ-specific dependencies
    );
}
```

---

## SenderView Factory

```php
private function createSender(
    string $uid = 'UID1',
    string $nick = 'TestUser',
    bool $isIdentified = false,
    bool $isOper = false,
): SenderView {
    return new SenderView(
        uid: $uid,
        nick: $nick,
        ident: 'user',
        hostname: 'example.com',
        cloakedHost: 'cloaked.example.com',
        ipBase64: 'base64ip',
        isIdentified: $isIdentified,
        isOper: $isOper,
        serverSid: 'SID1',
        displayHost: 'example.com',
        modes: '',
    );
}
```

---

## Capturing Messages

```php
private array $messages = [];

protected function setUp(): void
{
    $this->messages = [];

    $this->notifier = $this->createStub(NickServNotifierInterface::class);
    $this->notifier->method('sendMessage')
        ->willReturnCallback(function (string $target, string $message): void {
            $this->messages[] = $message;
        });

    $this->translator = $this->createStub(TranslatorInterface::class);
    $this->translator->method('trans')
        ->willReturnCallback(static fn (string $id): string => $id);
}
```

---

## Coverage Requirements

1. Every public method must have at least one test
2. Every branch (`if`/`else`) must be tested
3. Every early return must be tested
4. Edge cases: null values, empty arrays, boundary values

### Checking Coverage

```bash
./scripts/check-coverage.sh 100
```

### Finding Uncovered Lines

```bash
grep 'count="0"' var/coverage/clover.xml
```

---

## Live MCP Validation

For new or changed service commands, run live IRC/MariaDB MCP smoke checks when the MCP servers are available and the check can be performed safely.

Live MCP validation is additive:

- It does not replace PHPUnit.
- It does not reduce the 100% coverage requirement.
- It must never mutate real user/channel resources.

Use `.agents/services/live-mcp-testing.md` before running live checks. Create temporary nicks/channels for the validation, such as `NickTest<timestamp>` and `#test-<timestamp>`. For registration flows, MariaDB MCP may be used read-only to find the temporary nick verification token and complete the normal command flow without relying on email delivery.

If the command requires root, IRCop, or founder privileges, use `OPENCODE_IRC_ROOT_NICK` only for that step and only against temporary resources created for the same validation.

---

## Common Test Patterns

### Testing Authorization

```php
#[Test]
public function executeWhenNotIdentifiedRepliesError(): void
{
    $sender = $this->createSender(nick: 'User', isIdentified: false);
    $context = $this->createContext($sender, ['arg']);
    $this->cmd->execute($context);
    self::assertStringContainsString('error.not_identified', $this->messages[0]);
}

#[Test]
public function executeWhenIdentifiedButNotOwnerRepliesError(): void
{
    $sender = $this->createSender(nick: 'User', isIdentified: true);
    $account = $this->createStub(RegisteredNick::class);
    $account->method('getNickname')->willReturn('OtherNick');
    $context = $this->createContext($sender, ['arg'], $account);
    $this->cmd->execute($context);
    self::assertStringContainsString('error.permission_denied', $this->messages[0]);
}
```

### Testing IRCop Commands

```php
#[Test]
public function getRequiredPermissionReturnsIrcopPermission(): void
{
    $cmd = new KillCommand(/* ... */);
    self::assertSame(OperServPermission::KILL, $cmd->getRequiredPermission());
}

#[Test]
public function targetIsIrcopGetsProtectedError(): void
{
    $targetIrcop = OperIrcop::create(42, $role, 1, null);
    $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
    $ircopRepo->method('findByNickId')->willReturn($targetIrcop);
    // ... execute and assert error
}

#[Test]
public function rootUserCannotBeTargeted(): void
{
    $rootRegistry = new RootUserRegistry('RootNick');
    // ... test root protection
}
```

### Testing ChanServ Suspended/Forbidden Channel Checks

```php
#[Test]
public function allowsSuspendedChannelReturnsFalse(): void
{
    self::assertFalse($this->cmd->allowsSuspendedChannel());
}

#[Test]
public function allowsForbiddenChannelReturnsFalse(): void
{
    self::assertFalse($this->cmd->allowsForbiddenChannel());
}
```

---

## Related Skills

- `.agents/testing/README.md` — General testing rules
- `.agents/testing/testing-patterns.md` — Broader test patterns
- `.agents/testing/testing-coverage-priorities.md` — Test priorities map
- `.agents/services/commands.md` — Command structure reference
- `.agents/services/commands-permissions.md` — Permission system
- `.agents/services/live-mcp-testing.md` — Live IRC/MariaDB MCP validation
