<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Integration;

use App\ChanServ\Adapter\Out\Network\IrcNetworkIdentityFold;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkick;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Network\CaseMappingSessionSubscriber;
use App\Irc\Adapter\Network\NetworkIdentityEquivalenceTracker;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Services resolve network names and match AKICK masks with the casemapping each protocol
 * negotiates: on ASCII links `[` and `{` are different identities, on RFC 1459 links they are one.
 */
#[CoversClass(NetworkIdentityEquivalenceTracker::class)]
#[CoversClass(RegisteredChannelDoctrineRepository::class)]
#[CoversClass(RegisteredNickDoctrineRepository::class)]
#[CoversClass(EnforceChannelAkickHandler::class)]
#[CoversClass(IrcNetworkIdentityFold::class)]
final class NegotiatedCaseMappingEnforcementTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function registrationsResolveUnderTheNegotiatedMappingAndRevertToAsciiOnLinkLoss(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00:00');
        $this->entityManager->persist(RegisteredNick::createActive('Foo[', 'hash', null, 'en', $now));
        $this->entityManager->persist(RegisteredChannel::register($now, '#foo[', 1, 'desc'));
        $this->flushAndClear();

        foreach ($this->negotiators() as $protocol => $negotiate) {
            [$dispatcher, $tracker] = $this->link();
            $nicks = new RegisteredNickDoctrineRepository($this->entityManager, $tracker);
            $channels = new RegisteredChannelDoctrineRepository($this->entityManager, $tracker);

            $nick = static fn (string $name): ?string => $nicks->findByNick($name)?->getNickname();
            $channel = static fn (string $name): ?string => $channels->findByChannelName($name)?->getName();

            self::assertNull($nick('foo{'), $protocol . ' before negotiation');
            $negotiate($dispatcher, 'rfc1459');
            self::assertSame('Foo[', $nick('foo{'), $protocol);
            self::assertSame('#foo[', $channel('#FOO{'), $protocol);
            $negotiate($dispatcher, 'ascii');
            self::assertNull($nick('foo{'), $protocol);
            self::assertNull($channel('#foo{'), $protocol);
            self::assertTrue($channels->existsByChannelName('#foo{'), $protocol . ' keeps the RFC 1459 key unique');
            $negotiate($dispatcher, 'rfc1459');
            $dispatcher->dispatch(new ConnectionLostEvent($this->serverLink(), 'closed'));
            self::assertNull($channel('#foo{'), $protocol . ' after link loss');
        }
    }

    #[Test]
    public function joinEnforcementAndAkickMatchFollowTheNegotiatedMapping(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00:00');
        $channel = RegisteredChannel::register($now, '#foo[', 1, 'desc');
        $this->entityManager->persist($channel);
        $this->flushAndClear();
        $akick = ChannelAkick::create($now, $channel->getId(), 1, '[bot]*!*@*', 'bots');

        foreach (['ascii' => [0, 0, 1], 'rfc1459' => [1, 1, 1]] as $mapping => [$braceChannel, $braceNick, $bracketNick]) {
            [$dispatcher, $tracker] = $this->link();
            $this->negotiators()['unreal-standalone']($dispatcher, $mapping);

            self::assertSame($braceChannel, $this->enforce($tracker, $akick, '#foo{', '{bot}x!u@h'), $mapping . ' channel spelling');
            self::assertSame($braceNick, $this->enforce($tracker, $akick, '#foo[', '{bot}x!u@h'), $mapping . ' nick spelling');
            self::assertSame($bracketNick, $this->enforce($tracker, $akick, '#foo[', '[bot]x!u@h'), $mapping . ' exact spelling');
        }
    }

    private function enforce(NetworkIdentityEquivalenceTracker $tracker, ChannelAkick $akick, string $channelName, string $userMask): int
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMember')->willReturn(new ChannelEntryMember('001AAAAAA', 'member', $userMask, false, false, false));
        $handler = new EnforceChannelAkickHandler(
            new RegisteredChannelDoctrineRepository($this->entityManager, $tracker),
            $akicks,
            $network,
            $this->createStub(AkickActions::class),
            $this->createStub(ChanServActivitySink::class),
            new IrcNetworkIdentityFold($tracker),
        );

        return $handler->handle(new EnforceChannelAkick($channelName, '001AAAAAA', new \DateTimeImmutable('2026-11-15 10:00:00')));
    }

    /** @return array{EventDispatcher, NetworkIdentityEquivalenceTracker} */
    private function link(): array
    {
        $dispatcher = new EventDispatcher();
        $tracker = new NetworkIdentityEquivalenceTracker();
        $dispatcher->addSubscriber($tracker);
        $dispatcher->addSubscriber(new CaseMappingSessionSubscriber($dispatcher));

        return [$dispatcher, $tracker];
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

    private function serverLink(): ServerLink
    {
        return new ServerLink(new ServerName('test.example'), new Hostname('localhost'), new Port(6667), new LinkPassword('synthetic'), 'Test');
    }
}
