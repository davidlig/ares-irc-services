<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\NickServ\Service\NickForceService;
use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceNickReservationInterface;
use App\Application\Port\UserJoinedNetworkDTO;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use App\Infrastructure\OperServ\Subscriber\MotdOnConnectSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MotdOnConnectSubscriber::class)]
final class MotdOnConnectSubscriberTest extends TestCase
{
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

    private function sub(
        ?MotdRepositoryInterface $r = null,
        ?ServiceUidRegistry $u = null,
        ?ActiveConnectionHolderInterface $c = null,
        ?PseudoClientUidGenerator $p = null,
        ?NetworkUserLookupPort $l = null,
        ?RegisteredNickRepositoryInterface $n = null,
        ?SendNoticePort $s = null,
        ?NickForceService $f = null,
    ): MotdOnConnectSubscriber {
        return new MotdOnConnectSubscriber(
            $r ?? $this->createStub(MotdRepositoryInterface::class),
            $u ?? $this->createStub(ServiceUidRegistry::class),
            $c ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $p ?? $this->createStub(PseudoClientUidGenerator::class),
            $l ?? $this->createStub(NetworkUserLookupPort::class),
            $n ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $s ?? $this->createStub(SendNoticePort::class),
            $f ?? $this->createStub(NickForceService::class),
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
        self::assertArrayHasKey(NetworkSyncCompleteEvent::class, $ev);
        self::assertArrayHasKey(UserJoinedNetworkAppEvent::class, $ev);
    }

    #[Test]
    public function noSyncNoSend(): void
    {
        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::never())->method('sendMessage');

        $m = Motd::create('Hi', 'NickServ', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn('001NS');

        $x = $this->sub(r: $r, u: $u, s: $s);
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function serviceNickSends(): void
    {
        $m = Motd::create('Hi', 'NickServ', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn('001NS');

        $s = $this->createMock(SendNoticePort::class);
        $s->expects(self::once())->method('sendMessage')->with('001NS', '001ABC', 'Hi', 'PRIVMSG');

        $x = $this->sub(r: $r, u: $u, s: $s);
        $x->onSyncComplete();
        $x->onUserJoined(new UserJoinedNetworkAppEvent($this->dto()));
    }

    #[Test]
    public function pseudoClientIntroducedAndSends(): void
    {
        $m = Motd::create('Hi', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findAll')->willReturn([$m]);

        $activeCalls = [[$m], [$m], [$m]];
        $ai = 0;
        $r->method('findActive')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);

        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $a = Motd::create('First', 'test!bot@h.com', 'PRIVMSG');
        $b = Motd::create('Second', 'test!bot@h.com', 'NOTICE');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findAll')->willReturn([$a, $b]);

        $activeCalls = [[$a, $b], [$a, $b], [$a, $b]];
        $ai = 0;
        $r->method('findActive')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $a = Motd::create('A', 'test!bot@h.com', 'PRIVMSG');
        $b = Motd::create('B', 'test!bot@h.com', 'NOTICE');
        $r = $this->createStub(MotdRepositoryInterface::class);

        $activeCalls = [[$a, $b], [$a], [$a]];
        $ai = 0;
        $r->method('findActive')->willReturnCallback(
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
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $a = Motd::create('A', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);

        $activeCalls = [[$a], [$a], []];
        $ai = 0;
        $r->method('findActive')->willReturnCallback(
            static function () use (&$activeCalls, &$ai): array {
                return $activeCalls[$ai++];
            },
        );

        $r->method('findAll')->willReturn([]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::once())->method('quitPseudoClient')
            ->with('0A0', '0A0Z00001', 'MOTD expired');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $m = Motd::create('Late', 'late!bot@h.com', 'NOTICE');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);
        $r->method('findAll')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');
        $sa->expects(self::never())->method('quitPseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $m = Motd::create('Hi', 'test!bot@h.example', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findAll')->willReturn([$m]);
        $r->method('findActive')->willReturn([$m]);

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

        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $f = $this->createMock(NickForceService::class);
        $f->expects(self::once())->method('forceGuestNick')
            ->with('X', null, 'motd-collision');

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $m = Motd::create('Hi', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn($this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class));

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::never())->method('introducePseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $x = $this->sub(r: $r, u: $u, c: $c, l: $l, n: $n);
        $x->onSyncComplete();
    }

    #[Test]
    public function skipWhenGenerateReturnsNull(): void
    {
        $m = Motd::create('Hi', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::never())->method('introducePseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $m = Motd::create('Hi', 'NickServ', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn('001NS');

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::never())->method('introducePseudoClient');

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
        $c->method('getProtocolModule')->willReturn($this->mod($sa));
        $c->method('getServerSid')->willReturn('0A0');

        $x = $this->sub(r: $r, u: $u, c: $c);
        $x->onSyncComplete();
    }

    #[Test]
    public function futureExpiryReserveDuration(): void
    {
        $m = Motd::create('Timed', 'timer!bot@h.example', 'NOTICE', null, new DateTimeImmutable('+1 hour'));
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);
        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);
        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $nr = $this->createMock(ServiceNickReservationInterface::class);
        $nr->expects(self::once())->method('reserveNickWithDuration');

        $sa = $this->createMock(ProtocolServiceActionsInterface::class);
        $sa->expects(self::once())->method('introducePseudoClient');

        $mod = $this->createStub(ProtocolModuleInterface::class);
        $mod->method('getServiceActions')->willReturn($sa);
        $mod->method('getNickReservation')->willReturn($nr);

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $m = Motd::create('Bad', 'not_a_mask', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
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
        $m = Motd::create('Test', 'test!bot@h.com', 'PRIVMSG');
        $r = $this->createStub(MotdRepositoryInterface::class);
        $r->method('findActive')->willReturn([$m]);

        $u = $this->createStub(ServiceUidRegistry::class);
        $u->method('getUidByNickname')->willReturn(null);

        $l = $this->createStub(NetworkUserLookupPort::class);
        $l->method('findByNick')->willReturn(null);

        $n = $this->createStub(RegisteredNickRepositoryInterface::class);
        $n->method('findByNick')->willReturn(null);

        $c = $this->createStub(ActiveConnectionHolderInterface::class);
        $c->method('getProtocolModule')->willReturn(null);
        $c->method('getServerSid')->willReturn(null);

        $x = $this->sub(r: $r, u: $u, c: $c, l: $l, n: $n);
        $x->onSyncComplete();

        self::assertTrue(true);
    }
}
