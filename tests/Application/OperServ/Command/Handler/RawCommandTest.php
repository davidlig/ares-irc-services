<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\RawCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RawCommand::class)]
final class RawCommandTest extends TestCase
{
    private function createAccessHelper(): IrcopAccessHelper
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(\App\Domain\OperServ\Repository\OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): OperServContext {
        return new OperServContext(
            $sender,
            null,
            'RAW',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            $this->createAccessHelper(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): \App\Application\ApplicationPort\ServiceNicknameRegistry
    {
        $provider = new class('operserv', 'OperServ') implements \App\Application\ApplicationPort\ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new \App\Application\ApplicationPort\ServiceNicknameRegistry([$provider]);
    }

    private function createCommand(
        ?ActiveConnectionHolderInterface $connectionHolder = null,
    ): RawCommand {
        return new RawCommand(
            $connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function getNameReturnsRaw(): void
    {
        self::assertSame('RAW', $this->createCommand()->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand()->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        self::assertSame(1, $this->createCommand()->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        self::assertSame('raw.syntax', $this->createCommand()->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        self::assertSame('raw.help', $this->createCommand()->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsCorrectOrder(): void
    {
        self::assertSame(40, $this->createCommand()->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        self::assertSame('raw.short', $this->createCommand()->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand()->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        self::assertTrue($this->createCommand()->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsRaw(): void
    {
        self::assertSame(OperServPermission::RAW, $this->createCommand()->getRequiredPermission());
    }

    #[Test]
    public function nullSenderReturnsEarly(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $translator = $this->createStub(TranslatorInterface::class);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(null, ['test'], $notifier, $translator));
    }

    #[Test]
    public function emptyLineRepliesEmpty(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext($sender, ['   '], $notifier, $translator));

        self::assertStringContainsString('raw.empty', $messages[0]);
    }

    #[Test]
    public function lineTooLongRepliesTooLong(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $cmd = $this->createCommand($connectionHolder);
        $cmd->execute($this->createContext($sender, [str_repeat('x', 511)], $notifier, $translator));

        self::assertStringContainsString('raw.too_long', $messages[0]);
    }

    #[Test]
    public function notConnectedRepliesNotConnected(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('isConnected')->willReturn(false);

        $cmd = $this->createCommand($connectionHolder);
        $cmd->execute($this->createContext($sender, [':0A0BBBBBB MODE #opers +q 994AAAAAA'], $notifier, $translator));

        self::assertStringContainsString('raw.not_connected', $messages[0]);
    }

    #[Test]
    public function successWritesLineAndSetsAuditData(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $connectionHolder = $this->createMock(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('isConnected')->willReturn(true);
        $connectionHolder->expects(self::once())->method('writeLine')->with(':0A0BBBBBB MODE #opers +q 994AAAAAA');

        $cmd = $this->createCommand($connectionHolder);
        $cmd->execute($this->createContext($sender, [':0A0BBBBBB', 'MODE', '#opers', '+q', '994AAAAAA'], $notifier, $translator));

        self::assertStringContainsString('raw.done', $messages[0]);

        $auditData = $cmd->getAuditData($this->createContext($sender, [], $notifier, $translator));
        self::assertNotNull($auditData);
        self::assertSame(':0A0BBBBBB MODE #opers +q 994AAAAAA', $auditData->target);
        self::assertSame('Executed by TestUser', $auditData->reason);
    }
}
