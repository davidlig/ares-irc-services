<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\Command\ListCommand as ChanServListCommand;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Adapter\In\Event\CtcpVersionResponder;
use App\NickServ\Adapter\In\Irc\Command\ListCommand as NickServListCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Application\Security\NickServPermission;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class ServicesListIntegrationTest extends KernelTestCase
{
    #[Test]
    public function registersBothListCommandsWithTheirDefaultPageSizes(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $nickServ = $container->get(NickServCommandRegistry::class);
        self::assertInstanceOf(NickServCommandRegistry::class, $nickServ);
        $nickList = $nickServ->find('LIST');
        self::assertInstanceOf(NickServListCommand::class, $nickList);
        self::assertSame(NickServPermission::LIST, $nickList->getRequiredPermission());

        $chanServ = $container->get(ChanServCommandRegistry::class);
        self::assertInstanceOf(ChanServCommandRegistry::class, $chanServ);
        $chanList = $chanServ->find('LIST');
        self::assertInstanceOf(ChanServListCommand::class, $chanList);
        self::assertSame(ChanServPermission::LIST, $chanList->getRequiredPermission());

        self::assertSame(50, $container->getParameter('nickserv.list_page_size'));
        self::assertSame(50, $container->getParameter('chanserv.list_page_size'));
        self::assertSame(50, $container->getParameter('nickserv.list_page_size_default'));
        self::assertSame(50, $container->getParameter('chanserv.list_page_size_default'));
    }

    #[Test]
    public function exposesTheConfiguredServiceVersionThroughCtcp(): void
    {
        self::bootKernel();
        $responder = self::getContainer()->get(CtcpVersionResponder::class);

        self::assertInstanceOf(CtcpVersionResponder::class, $responder);
        self::assertSame('Ares IRC Services v2.1.3', $responder->getVersionResponse());
    }
}
