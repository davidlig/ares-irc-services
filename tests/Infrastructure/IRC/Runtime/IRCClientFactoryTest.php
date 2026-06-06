<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Runtime;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Domain\IRC\Connection\ConnectionFactoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Runtime\IRCClient;
use App\Infrastructure\IRC\Runtime\IRCClientFactory;
use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleInterface;
use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IRCClientFactory::class)]
final class IRCClientFactoryTest extends TestCase
{
    private MockObject&ProtocolRuntimeModuleRegistryInterface $moduleRegistry;

    private ConnectionFactoryInterface&MockObject $connectionFactory;

    private ActiveConnectionHolder $connectionHolder;

    private IRCClientFactory $factory;

    private ServerLink $link;

    protected function setUp(): void
    {
        $this->moduleRegistry = $this->createMock(ProtocolRuntimeModuleRegistryInterface::class);
        $this->connectionFactory = $this->createMock(ConnectionFactoryInterface::class);
        $this->connectionHolder = new ActiveConnectionHolder();

        $this->factory = new IRCClientFactory(
            $this->moduleRegistry,
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
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('getProtocolName')->willReturn('unreal');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);

        $this->moduleRegistry->expects(self::once())->method('get')->with('unreal')->willReturn($module);
        $this->connectionFactory->expects(self::once())->method('create')->with($this->link)->willReturn($connection);

        $client = $this->factory->create('unreal', $this->link);

        self::assertInstanceOf(IRCClient::class, $client);
        self::assertSame('unreal', $client->getProtocolName());
        self::assertSame($module, $this->connectionHolder->getProtocolModule());
    }
}
