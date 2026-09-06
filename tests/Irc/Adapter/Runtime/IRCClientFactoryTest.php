<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Runtime;

use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Infrastructure\IRC\Runtime\LoopSchedulerInterface;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionFactoryInterface;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\IRCClientFactory;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\BurstCompleteRegistry;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IRCClientFactory::class)]
final class IRCClientFactoryTest extends TestCase
{
    private ProtocolRuntimeModuleInterface $module;

    private ConnectionFactoryInterface&MockObject $connectionFactory;

    private ActiveConnectionHolder $connectionHolder;

    private IRCClientFactory $factory;

    private ServerLink $link;

    protected function setUp(): void
    {
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('getProtocolName')->willReturn('unreal');
        $this->module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $this->module->method('getHandler')->willReturn($handler);
        $this->connectionFactory = $this->createMock(ConnectionFactoryInterface::class);
        $this->connectionHolder = new ActiveConnectionHolder();

        $this->factory = new IRCClientFactory(
            $this->module,
            $this->connectionFactory,
            $this->connectionHolder,
            $this->createStub(EventBusInterface::class),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            new BurstCompleteRegistry(),
            60,
        );

        $this->link = new ServerLink(
            new ServerName('irc.test.local'),
            new Hostname('127.0.0.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test',
            false,
        );
    }

    #[Test]
    public function createReturnsIRCClientAndSetsModuleOnHolder(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $this->connectionFactory->expects(self::once())->method('create')->with($this->link)->willReturn($connection);

        $client = $this->factory->create($this->link);

        self::assertSame('unreal', $client->getProtocolName());
        self::assertSame($this->module, $this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function createWithCustomLoopScheduler(): void
    {
        $scheduler = $this->createStub(LoopSchedulerInterface::class);
        $factory = new IRCClientFactory(
            $this->module,
            $this->connectionFactory,
            $this->connectionHolder,
            $this->createStub(EventBusInterface::class),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            new BurstCompleteRegistry(),
            60,
            loopScheduler: $scheduler,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $this->connectionFactory->expects(self::once())->method('create')->with($this->link)->willReturn($connection);

        $client = $factory->create($this->link);

        self::assertSame('unreal', $client->getProtocolName());
    }
}
