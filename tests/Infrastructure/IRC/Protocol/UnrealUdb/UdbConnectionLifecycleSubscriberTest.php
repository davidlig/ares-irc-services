<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbConnectionLifecycleSubscriber;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProviderInterface;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbConnectionLifecycleSubscriber::class)]
final class UdbConnectionLifecycleSubscriberTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    #[Test]
    public function connectionLostResetsTheCoordinatorSoTheNextLinkStartsOver(): void
    {
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
        );
        $subscriber = new UdbConnectionLifecycleSubscriber($coordinator);

        $coordinator->setOwnName('services.example.net');
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $coordinator->onLinkReady($this->createConnection());

        // First link announced HEL once; a lost connection must clear the
        // volatile state so the replacement link re-announces it.
        $subscriber->onConnectionLost(new ConnectionLostEvent($this->createServerLink(), 'connection reset'));

        $this->written = [];
        // The new link re-captures both identities before HEL.
        $coordinator->setOwnName('services.example.net');
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $coordinator->onLinkReady($this->createConnection());

        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.example\.net [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());
    }

    #[Test]
    public function subscribedEventsIncludeConnectionLost(): void
    {
        $events = UdbConnectionLifecycleSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConnectionLostEvent::class, $events);
    }

    private function createConnection(): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        return $connection;
    }

    private function firstWrittenLine(): string
    {
        foreach ($this->written as $line) {
            return $line;
        }

        self::fail('Expected at least one written line.');
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            serverName: new ServerName('services.test.local'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7000),
            password: new LinkPassword('secret'),
            description: 'Ares IRC Services',
        );
    }
}
