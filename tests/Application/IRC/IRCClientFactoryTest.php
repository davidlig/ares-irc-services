<?php

declare(strict_types=1);

namespace App\Tests\Application\IRC;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\IRC\IRCClient;
use App\Application\IRC\IRCClientFactory;
use App\Application\Port\ProtocolModuleRegistryInterface;
use App\Domain\IRC\Connection\ConnectionFactoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(IRCClientFactory::class)]
final class IRCClientFactoryTest extends TestCase
{
    private ProtocolModuleRegistryInterface&MockObject $moduleRegistry;

    private ConnectionFactoryInterface&MockObject $connectionFactory;

    private ActiveConnectionHolder $connectionHolder;

    private IRCClientFactory $factory;

    private ServerLink $link;

    protected function setUp(): void
    {
        $this->moduleRegistry = $this->createMock(ProtocolModuleRegistryInterface::class);
        $this->connectionFactory = $this->createMock(ConnectionFactoryInterface::class);
        $this->connectionHolder = new ActiveConnectionHolder();

        $this->factory = new IRCClientFactory(
            $this->moduleRegistry,
            $this->connectionFactory,
            $this->connectionHolder,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
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
        $connection = $this->createMock(ConnectionInterface::class);
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->method('getProtocolName')->willReturn('unreal');
        $module = $this->createMock(\App\Application\Port\ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);

        $this->moduleRegistry->expects(self::once())->method('get')->with('unreal')->willReturn($module);
        $this->connectionFactory->expects(self::once())->method('create')->with($this->link)->willReturn($connection);

        $client = $this->factory->create('unreal', $this->link);

        self::assertInstanceOf(IRCClient::class, $client);
        self::assertSame('unreal', $client->getProtocolName());
        self::assertSame($module, $this->connectionHolder->getProtocolModule());
    }
}
