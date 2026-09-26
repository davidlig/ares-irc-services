<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\HelpCommand;
use App\ChanServ\Adapter\In\Irc\Command\IrcoponlyCommand;
use App\ChanServ\Adapter\In\Irc\Command\ListCommand;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\OperServ\Application\Port\In\OperatorPermissionCatalog;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversNothing]
final class ChanServCommandWiringTest extends KernelTestCase
{
    #[Test]
    public function ircoponlyIsRoutableAndItsPermissionIsPublished(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(ChanServCommandRegistry::class);
        self::assertInstanceOf(ChanServCommandRegistry::class, $commands);
        $command = $commands->find('IRCOPONLY');
        self::assertInstanceOf(IrcoponlyCommand::class, $command);
        self::assertSame(ChanServPermission::IRCOPONLY, $command->getRequiredPermission());

        $permissions = self::getContainer()->get(OperatorPermissionCatalog::class);
        self::assertInstanceOf(OperatorPermissionCatalog::class, $permissions);
        self::assertContains(ChanServPermission::IRCOPONLY, $permissions->getAllPermissions());
    }

    #[Test]
    public function listIsRoutableAndItsPermissionAndPageSizeArePublished(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(ChanServCommandRegistry::class);
        self::assertInstanceOf(ChanServCommandRegistry::class, $commands);
        $command = $commands->find('LIST');
        self::assertInstanceOf(ListCommand::class, $command);
        self::assertSame(ChanServPermission::LIST, $command->getRequiredPermission());

        $permissions = self::getContainer()->get(OperatorPermissionCatalog::class);
        self::assertInstanceOf(OperatorPermissionCatalog::class, $permissions);
        self::assertContains(ChanServPermission::LIST, $permissions->getAllPermissions());
        self::assertSame(50, self::getContainer()->getParameter('chanserv.list_page_size'));
    }

    #[Test]
    public function ircoponlyAppearsInHelpForAnOperatorWithPermission(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(ChanServCommandRegistry::class);
        self::assertInstanceOf(ChanServCommandRegistry::class, $commands);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params): string {
            if ('help.command_line' !== $key) {
                return $key;
            }
            $command = $params['%command%'] ?? null;
            self::assertIsString($command);

            return $command;
        });
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('hasAnyPermission')->willReturn(true);
        $access->method('hasPermission')->willReturnCallback(static fn (string $nick, ?int $id, bool $identified, bool $oper, string $permission): bool => ChanServPermission::IRCOPONLY === $permission);
        $context = new ChanServContext(
            new SenderView('U1', 'Oper', 'ident', 'host', 'cloak', '*', true, true, 'SID', 'host', 'o'),
            new ChanAccountView(1, 'Oper', 'en'),
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $commands,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            new ServiceNicknameRegistry([]),
        );

        new HelpCommand(new UnifiedHelpFormatter(), $access)->execute($context);

        self::assertContains('IRCOPONLY   ', $messages);
    }
}
