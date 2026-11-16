<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Network\CaseMappingSessionSubscriber;
use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Adapter\Network\NetworkIdentityEquivalenceTracker;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(NetworkIdentityEquivalenceTracker::class)]
#[CoversClass(CaseMappingSessionSubscriber::class)]
#[CoversClass(CaseMapping::class)]
#[CoversClass(CaseMappingChangedEvent::class)]
final class NetworkIdentityEquivalenceTrackerTest extends TestCase
{
    #[Test]
    public function asciiIsTheDefaultBeforeNegotiation(): void
    {
        $tracker = new NetworkIdentityEquivalenceTracker();

        self::assertTrue($tracker->equivalent('Foo[', 'fOO['));
        self::assertFalse($tracker->equivalent('Foo[', 'foo{'));
        self::assertSame('#foo[', $tracker->fold('#FOO['));
    }

    #[Test]
    public function equivalenceFollowsTheMappingEachProtocolNegotiates(): void
    {
        foreach (['rfc1459' => true, 'ascii' => false, 'strict-rfc1459' => true] as $mapping => $bracesEqual) {
            foreach ($this->negotiators() as $protocol => $negotiate) {
                $dispatcher = new EventDispatcher();
                $tracker = new NetworkIdentityEquivalenceTracker();
                $dispatcher->addSubscriber($tracker);
                $negotiate($dispatcher, $mapping);

                self::assertSame($bracesEqual, $tracker->equivalent('#Foo[', '#foo{'), $protocol . ' ' . $mapping);
                self::assertSame('rfc1459' === $mapping, $tracker->equivalent('Nick^', 'nick~'), $protocol . ' ' . $mapping);
                self::assertSame(CaseMapping::from($mapping)->fold('Nick[^]'), $tracker->fold('Nick[^]'), $protocol . ' ' . $mapping);
            }
        }
    }

    #[Test]
    public function linkLifecycleRestoresTheAsciiDefault(): void
    {
        $dispatcher = new EventDispatcher();
        $tracker = new NetworkIdentityEquivalenceTracker();
        $dispatcher->addSubscriber($tracker);
        $dispatcher->addSubscriber(new CaseMappingSessionSubscriber($dispatcher));
        $link = new ServerLink(new ServerName('test.example'), new Hostname('localhost'), new Port(6667), new LinkPassword('synthetic'), 'Test');
        foreach ([new ConnectionEstablishedEvent($link), new ConnectionLostEvent($link, 'closed')] as $event) {
            $dispatcher->dispatch(new CaseMappingChangedEvent(CaseMapping::Rfc1459));
            self::assertTrue($tracker->equivalent('Foo[', 'foo{'));
            $dispatcher->dispatch($event);
            self::assertFalse($tracker->equivalent('Foo[', 'foo{'));
        }
    }

    /** @return array<string, callable(EventDispatcher, string): void> */
    private function negotiators(): array
    {
        $connection = $this->createStub(ConnectionInterface::class);

        return [
            'inspircd' => static function (EventDispatcher $dispatcher, string $mapping) use ($connection): void {
                $handler = new InspIRCdProtocolHandler(eventDispatcher: $dispatcher);
                foreach (['CAPAB START 1206', 'CAPAB MODULES :services account chghost cloak', 'CAPAB CAPABILITIES :CASEMAPPING=' . $mapping, 'CAPAB END'] as $line) {
                    $handler->handleIncoming(IRCMessage::fromRawLine($line), $connection);
                }
            },
            'unreal-standalone' => static function (EventDispatcher $dispatcher, string $mapping): void {
                new UnrealStandaloneNetworkStateAdapter($dispatcher)->handleMessage(new IRCMessage('PROTOCTL', null, ['CASEMAPPING=' . $mapping]));
            },
            'unreal-udb' => static function (EventDispatcher $dispatcher, string $mapping): void {
                new UnrealUdbNetworkStateAdapter($dispatcher)->handleMessage(new IRCMessage('PROTOCTL', null, ['CASEMAPPING=' . $mapping]));
            },
        ];
    }
}
