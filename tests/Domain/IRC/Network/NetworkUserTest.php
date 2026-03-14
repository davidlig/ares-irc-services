<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Network;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetworkUser::class)]
final class NetworkUserTest extends TestCase
{
    private function createUser(string $modes = '+i', string $ipBase64 = '*'): NetworkUser
    {
        return new NetworkUser(
            uid: new Uid('AAA111'),
            nick: new Nick('Nick'),
            ident: new Ident('ident'),
            hostname: 'host.example',
            cloakedHost: 'cloak.example',
            virtualHost: 'vhost.example',
            modes: $modes,
            connectedAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
            realName: 'Real Name',
            serverSid: '001',
            ipBase64: $ipBase64,
        );
    }

    #[Test]
    public function nickAndVirtualHostAndModesCanChange(): void
    {
        $user = $this->createUser('+i');

        $user->changeNick(new Nick('Other'));
        self::assertSame('Other', $user->getNick()->value);

        $user->updateVirtualHost('new.vhost');
        self::assertSame('new.vhost', $user->getVirtualHost());

        $user->updateModes('+io');
        self::assertSame('+io', $user->getModes());
    }

    #[Test]
    public function channelsAreTrackedByLowercasedName(): void
    {
        $user = $this->createUser();

        $user->addChannel(new ChannelName('#Foo'));
        $user->addChannel(new ChannelName('#bar'));

        self::assertSame(['#Foo', '#bar'], $user->getChannelNames());

        $user->removeChannel(new ChannelName('#FOO'));
        self::assertSame(['#bar'], $user->getChannelNames());
    }

    #[Test]
    public function applyModeChangeUpdatesFlagsAndHelpers(): void
    {
        $user = $this->createUser('+i');

        $user->applyModeChange('+or');
        self::assertSame('+ior', $user->getModes());
        self::assertTrue($user->isOper());
        self::assertTrue($user->isIdentified());
        self::assertFalse($user->isBot());

        $user->applyModeChange('+B-o');
        self::assertTrue($user->isBot());
        self::assertFalse(str_contains($user->getModes(), 'o'));
    }

    #[Test]
    public function ipAddressIsDecodedFromBase64(): void
    {
        $binary = inet_pton('127.0.0.1');
        self::assertNotFalse($binary);
        $encoded = base64_encode($binary);

        $user = $this->createUser('+i', $encoded);

        self::assertSame('127.0.0.1', $user->getIpAddress());
    }

    #[Test]
    public function ipAddressReturnsAsteriskWhenMasked(): void
    {
        $user = $this->createUser('+i', '*');

        self::assertSame('*', $user->getIpAddress());
    }

    #[Test]
    public function ipAddressFallsBackOnDecodeFailure(): void
    {
        $user = $this->createUser('+i', 'not-base64');

        self::assertSame('not-base64', $user->getIpAddress());
    }

    #[Test]
    public function ipAddressFallsBackWhenInetNtopFails(): void
    {
        $invalidLengthBinary = base64_encode("\x00\x00\x00");
        $user = $this->createUser('+i', $invalidLengthBinary);

        self::assertSame($invalidLengthBinary, $user->getIpAddress());
    }

    #[Test]
    public function displayHostUsesVirtualHostWhenSet(): void
    {
        $user = $this->createUser('+i');
        self::assertSame('vhost.example', $user->getDisplayHost());

        $user = $this->createUser('+i');
        $user->updateVirtualHost('*');
        self::assertSame('cloak.example', $user->getDisplayHost());
    }

    #[Test]
    public function toArrayExposesKeyFields(): void
    {
        $user = $this->createUser('+io', 'not-base64');

        $data = $user->toArray();

        self::assertSame('AAA111', $data['uid']);
        self::assertSame('Nick', $data['nick']);
        self::assertSame('ident', $data['ident']);
        self::assertSame('host.example', $data['hostname']);
        self::assertSame('cloak.example', $data['cloakedHost']);
        self::assertSame('vhost.example', $data['virtualHost']);
        self::assertSame('vhost.example', $data['displayHost']);
        self::assertSame('+io', $data['modes']);
        self::assertSame('Real Name', $data['realName']);
        self::assertSame('001', $data['server']);
        self::assertSame('not-base64', $data['ip']);
        self::assertNotEmpty($data['connectedAt']);
    }
}
