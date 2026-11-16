<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Network\ServerBurstRegistry;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ServerBurstRegistry::class)]
final class ServerBurstRegistryTest extends TestCase
{
    #[Test]
    public function aServerIsBurstingFromItsStartUntilItsEndOfBurst(): void
    {
        $registry = new ServerBurstRegistry();

        self::assertFalse($registry->isBursting('002'));

        $registry->startBurst('002');
        self::assertTrue($registry->isBursting('002'));
        self::assertFalse($registry->isBursting('003'));

        $registry->endBurst('002');
        self::assertFalse($registry->isBursting('002'));
    }

    #[Test]
    public function anUnknownOriginIsNeverBursting(): void
    {
        $registry = new ServerBurstRegistry();
        $registry->startBurst('002');

        self::assertFalse($registry->isBursting(null));
    }

    #[Test]
    public function endBurstAcceptsSeveralServers(): void
    {
        $registry = new ServerBurstRegistry();
        $registry->startBurst('002');
        $registry->startBurst('003');
        $registry->startBurst('004');

        $registry->endBurst('002', '003');

        self::assertFalse($registry->isBursting('002'));
        self::assertFalse($registry->isBursting('003'));
        self::assertTrue($registry->isBursting('004'));
    }

    #[Test]
    public function connectionLossForgetsEveryBurstingServer(): void
    {
        $registry = new ServerBurstRegistry();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($registry);
        $registry->startBurst('001');
        $registry->startBurst('002');

        $dispatcher->dispatch(new ConnectionLostEvent(new ServerLink(
            new ServerName('irc.example.com'),
            new Hostname('192.168.1.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test Server',
            true,
        ), 'Connection reset'));

        self::assertFalse($registry->isBursting('001'));
        self::assertFalse($registry->isBursting('002'));
    }

    #[Test]
    public function clearForgetsEveryBurstingServer(): void
    {
        $registry = new ServerBurstRegistry();
        $registry->startBurst('001');

        $registry->clear();

        self::assertFalse($registry->isBursting('001'));
    }
}
