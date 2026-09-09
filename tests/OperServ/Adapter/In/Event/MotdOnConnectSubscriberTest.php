<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceChannelRegistrationPort;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkDTO;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\In\NickCollisionResolver;
use App\OperServ\Adapter\In\Event\MotdOnConnectSubscriber;
use App\OperServ\Adapter\Out\Irc\PseudoClientUidGenerator;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MotdOnConnectSubscriber::class)]
final class MotdOnConnectSubscriberTest extends TestCase
{
    private int $nextMotdId = 1;

    private function dto(string $uid = '001ABC'): UserJoinedNetworkDTO
    {
        return new UserJoinedNetworkDTO(
            uid: $uid,
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'dGVzdA==',
            displayHost: 'test.example',
        );
    }

    private function motd(
        string $text,
        string $botNickname,
        string $messageType,
        ?int $creatorAccountId = null,
        ?DateTimeImmutable $expiresAt = null,
    ): MotdEntry {
        return new MotdEntry(
            $this->nextMotdId++,
            $text,
            $botNickname,
            'PRIVMSG' === $messageType ? MessageDelivery::Interactive : MessageDelivery::NonInteractive,
            true,
            new DateTimeImmutable('2026-09-08T00:00:00+00:00'),
            $expiresAt,
            0,
        );
    }

    private function sub(
        ?MotdRepository $r = null,
        ?ServiceUidRegistry $u = null,
        ?ActiveProtocolModuleHolderInterface $c = null,
        ?ChannelLookupPort $cl = null,
        ?ServiceChannelRegistrationPort $cr = null,
        ?PseudoClientUidGenerator $p = null,
        ?NetworkUserLookupPort $l = null,
        ?NickAccountQuery $n = null,
        ?SendNoticePort $s = null,
        ?NickCollisionResolver $f = null,
        ?string $debugChannel = null,
    ): MotdOnConnectSubscriber {
        return new MotdOnConnectSubscriber(
            $r ?? $this->createStub(MotdRepository::class),
            $u ?? $this->createStub(ServiceUidRegistry::class),
            $c ?? $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $cl ?? $this->createStub(ChannelLookupPort::class),
            $cr ?? $this->createStub(ServiceChannelRegistrationPort::class),
            $p ?? $this->createStub(PseudoClientUidGenerator::class),
            $l ?? $this->createStub(NetworkUserLookupPort::class),
            $n ?? $this->createStub(NickAccountQuery::class),
            $s ?? $this->createStub(SendNoticePort::class),
            $f ?? $this->createStub(NickCollisionResolver::class),
            $debugChannel,
        );
    }

    private function mod(ProtocolServiceActionsInterface $sa): ProtocolModuleInterface
    {
        $m = $this->createStub(ProtocolModuleInterface::class);
        $m->method('getServiceActions')->willReturn($sa);
        $m->method('getNickReservation')->willReturn($this->createStub(ServiceNickReservationInterface::class));

        return $m;
    }

    #[Test]
    public function events(): void
    {
        $ev = MotdOnConnectSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(NetworkSynchronizationCompletedEvent::class, $ev);
        self::assertArrayHasKey(UserJoinedNetworkAppEvent::class, $ev);
    }

    #[Test]
    public function noSyncNoSend(): void
    {
        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::never())->method('sendMessage');

        $m = $this->motd('Hi', 'NickServ', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn('001NS');

        $x = $this->sub(r: $r, u: $u, s: $s);
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function serviceNickSends(): void
    {
        $m = $this->motd('Hi', 'NickServ', 'PRIVMSG');
        $r = $this->createMock(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn('001NS');

        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::once())->method('sendMessage')->with('001NS', '001ABC', 'Hi', 'PRIVMSG');

        $r->expects(self::once())->method('recordShown')->with($m->id);

        $x = $this->sub(r: $r, u: $u, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function pseudoClientIntroducedAndSends(): void
    {
        $m = $this->motd('Hi', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findAll')->willReturn([$m]);

        $activeCalls = [[$m], [$m], [$m]];
        $ai = 0;
        $r->method('findActiveAt')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);

        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::once())->method('sendMessage')->with('0A0Z00001', '001ABC', 'Hi', 'PRIVMSG');

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function twoMotdsSameNameSharePseudoClient(): void
    {
        $a = $this->motd('First', 'test!bot@h.com', 'PRIVMSG');
        $b = $this->motd('Second', 'test!bot@h.com', 'NOTICE');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findAll')->willReturn([$a, $b]);

        $activeCalls = [[$a, $b], [$a, $b], [$a, $b]];
        $ai = 0;
        $r->method('findActiveAt')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $sent = [];
        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::exactly(2))->method('sendMessage')
            ->willReturnCallback(static function (string $f, string $t, string $msg) use (&$sent): void {
                $sent[] = $msg;
            });

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));

        self::assertSame(['First', 'Second'], $sent);
    }

    #[Test]
    public function delOneKeepsClientIfOthersRemain(): void
    {
        $a = $this->motd('A', 'test!bot@h.com', 'PRIVMSG');
        $b = $this->motd('B', 'test!bot@h.com', 'NOTICE');
        $r = $this->createStub(MotdRepository::class);

        $activeCalls = [[$a, $b], [$a], [$a]];
        $ai = 0;
        $r->method('findActiveAt')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $allCalls = [[$a, $b], [$a]];
        $allIdx = 0;
        $r->method('findAll')->willReturnCallback(
            static function () use (&$allCalls, &$allIdx): array {
                return $allCalls[$allIdx++];
            },
        );

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');
        $s = $this->createStub(SendNoticePort::class);

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function delLastQuitsPseudoClient(): void
    {
        $a = $this->motd('A', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);

        $activeCalls = [[$a], [$a], []];
        $ai = 0;
        $r->method('findActiveAt')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $r->method('findAll')->willReturn([]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::once())->method('quitPseudoClient')
            ->with('0A0', '0A0Z00001', 'MOTD expired');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function lateAddIntroducedOnNextJoin(): void
    {
        $m = $this->motd('Late', 'late!bot@h.com', 'NOTICE');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);
        $r->method('findAll')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00005');

        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::once())->method('sendMessage');

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function renameConnectedUserAndIntroducePseudoClient(): void
    {
        $m = $this->motd('Hi', 'test!bot@h.example', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findAll')->willReturn([$m]);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $existingUser = new SenderView(
            uid: 'X',
            nick: 'test',
            ident: 'x',
            hostname: 'x',
            cloakedHost: 'x',
            ipBase64: 'dA==',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
        );
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn($existingUser);

        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $f = $this->createMock(NickCollisionResolver::class);
        $f->expects(self::once())->method('forceGuestNick')
            ->with('X', null, 'motd-collision');

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n, f: $f);
        $x->onSyncComplete();
    }

    #[Test]
    public function skipWhenNickRegistered(): void
    {
        $m = $this->motd('Hi', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(1);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::never())->method('introducePseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $x = $this->sub(r: $r, u: $u, c: $c, l: $l, n: $n);
        $x->onSyncComplete();
    }

    #[Test]
    public function skipWhenGenerateReturnsNull(): void
    {
        $m = $this->motd('Hi', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::never())->method('introducePseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn(null);

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n);
        $x->onSyncComplete();
    }

    #[Test]
    public function skipServiceNickInEnsure(): void
    {
        $m = $this->motd('Hi', 'NickServ', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn('001NS');

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::never())->method('introducePseudoClient');

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $x = $this->sub(r: $r, u: $u, c: $c);
        $x->onSyncComplete();
    }

    #[Test]
    public function futureExpiryReserveDuration(): void
    {
        $m = $this->motd('Timed', 'timer!bot@h.example', 'NOTICE', null, new DateTimeImmutable('+1 hour'));
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $nr = $this->createMock(ServiceNickReservationInterface::class);
        $nr->expects(self::once())->method('reserveNickWithDuration');

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');

        $mod = $this->createStub(ProtocolModuleInterface::class);
        $mod->method('getServiceActions')->willReturn($sa);
        $mod->method('getNickReservation')->willReturn($nr);

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($mod);
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n);
        $x->onSyncComplete();
    }

    #[Test]
    public function invalidMaskInSendMotdsIsSkipped(): void
    {
        $m = $this->motd('Bad', 'not_a_mask', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($this->createStub(ProtocolServiceActionsInterface::class)));
        $c->method('getServerSid')->willReturn('0A0');

        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::never())->method('sendMessage');

        $x = $this->sub(r: $r, u: $u, c: $c, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function noModuleReturnsEarlyInEnsure(): void
    {
        $m = $this->motd('Test', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);

        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $c = $this->createMock(ActiveProtocolModuleHolderInterface::class);
        $c->expects(self::once())->method('getProtocolModule')->willReturn(null);
        $c->expects(self::once())->method('getServerSid')->willReturn(null);

        $x = $this->sub(r: $r, u: $u, c: $c, l: $l, n: $n);
        $x->onSyncComplete();
    }

    #[Test]
    public function customMotdBotJoinsDebugChannelWhenConfigured(): void
    {
        $m = $this->motd('Hi', 'custom!bot@h.example', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findAll')->willReturn([$m]);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);

        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::once())->method('joinChannelAsService')
            ->with('0A0', '#ircops', '0A0Z00001', '', 1234);

        $mod = $this->createStub(ProtocolModuleInterface::class);
        $mod->method('getServiceActions')->willReturn($sa);
        $mod->method('getNickReservation')->willReturn($this->createStub(ServiceNickReservationInterface::class));

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($mod);
        $c->method('getServerSid')->willReturn('0A0');

        $cl = $this->createStub(ChannelLookupPort::class);
        $cl->method('findByChannelName')->willReturn(new ChannelView('#ircops', '+nt', null, 1, timestamp: 1234));

        $cr = $this->createMock(ServiceChannelRegistrationPort::class);
        $cr->expects(self::once())->method('registerServiceChannelJoin')
            ->with('#ircops', '0A0Z00001', '', 1234);

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $x = $this->sub(r: $r, u: $u, c: $c, cl: $cl, cr: $cr, p: $p, l: $l, n: $n, debugChannel: '#ircops');
        $x->onSyncComplete();
    }

    #[Test]
    public function customMotdBotSkipsDebugJoinWhenConnectionDisappearsAfterIntroduction(): void
    {
        $m = $this->motd('Hi', 'custom!bot@h.example', 'PRIVMSG');
        $r = $this->createStub(MotdRepository::class);
        $r->method('findActiveAt')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);

        $n = $this->createStub(NickAccountQuery::class);
        $n->method('findIdByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('joinChannelAsService');

        $mod = $this->createStub(ProtocolModuleInterface::class);
        $mod->method('getServiceActions')->willReturn($sa);
        $mod->method('getNickReservation')->willReturn($this->createStub(ServiceNickReservationInterface::class));

        $c = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $c->method('getProtocolModule')->willReturnOnConsecutiveCalls($mod, null);
        $c->method('getServerSid')->willReturn('0A0');

        $p = $this->createStub(PseudoClientUidGenerator::class);
        $p->method('generate')->willReturn('0A0Z00001');

        $x = $this->sub(r: $r, u: $u, c: $c, p: $p, l: $l, n: $n, debugChannel: '#ircops');
        $x->onSyncComplete();
    }
}
