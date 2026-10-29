<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\ChannelBanMatcherProvider;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelBanMatcher;
use App\Irc\Adapter\ServiceBridge\CoreChannelBanMatchQuery;
use App\Irc\Application\Port\In\ChannelBanMatchResult;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function inet_pton;

#[CoversClass(CoreChannelBanMatchQuery::class)]
#[CoversClass(ChannelBanMatchResult::class)]
final class CoreChannelBanMatchQueryTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'literal v4' => ['192.0.2.7', true];
        yield 'encoded v4' => [base64_encode("\xc0\x00\x02\x07"), true];
        yield 'literal v6' => ['2001:db8::1', true];
        yield 'encoded v6' => [base64_encode((string) inet_pton('2001:db8::1')), true];
        yield 'missing' => ['*', false];
        yield 'empty' => ['', false];
        yield 'invalid' => ['%%%bad', false];
        yield 'short binary' => [base64_encode('abc'), false];
    }

    #[DataProvider('addresses')]
    public function testMatchesOriginalMasksWithoutMutatingChannel(string $ip, bool $available): void
    {
        $channel = new Channel(new ChannelName('#test'), '+nt', new DateTimeImmutable('@123'));
        $channel->addBan('ALICE!*@*.example');
        $channel->addBan('*!shown@visible.example');
        $channel->addBan('Bob!*@*');
        $channel->addBan('a:Alice!*@*');
        $before = $channel->getBans();
        $user = new NetworkUser(new Uid('001AAA'), new Nick('Alice'), new Ident('shown'), 'real.example', 'cloak.example', 'visible.example', '+r', new DateTimeImmutable('@123'), 'Alice', '001', $ip);
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('findByName')->willReturn($channel);
        $users = $this->createStub(NetworkUserRepositoryInterface::class);
        $users->method('findByUid')->willReturn($user);
        $module = $this->createStubForIntersectionOfInterfaces([ProtocolModuleInterface::class, ChannelBanMatcherProvider::class]);
        $module->method('getChannelBanMatcher')->willReturn(new InspIRCdChannelBanMatcher());
        $holder = new ActiveConnectionHolder();
        self::assertInstanceOf(ProtocolModuleInterface::class, $module);
        $holder->setProtocolModule($module);
        $query = new CoreChannelBanMatchQuery($channels, $users, $holder);
        $result = $query->findMatchingBans('#test', '001AAA');
        if ($available) {
            self::assertNotNull($result);
            self::assertTrue($result->channelExists);
            self::assertSame(['ALICE!*@*.example', '*!shown@visible.example'], $result->masks);
            self::assertSame(123, $result->channelTimestamp);
        } else {
            self::assertNull($result);
        }
        self::assertSame($before, $channel->getBans());
    }

    public function testAbsentChannelInvalidInputAndUnavailableRuntime(): void
    {
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $users = $this->createStub(NetworkUserRepositoryInterface::class);
        $holder = new ActiveConnectionHolder();
        $query = new CoreChannelBanMatchQuery($channels, $users, $holder);
        self::assertFalse($query->findMatchingBans('#absent', '001AAA')?->channelExists);
        self::assertNull($query->findMatchingBans('invalid', '001AAA'));
        $channel = new Channel(new ChannelName('#test'), '+nt', new DateTimeImmutable('@123'));
        $channels->method('findByName')->willReturn($channel);
        self::assertNull($query->findMatchingBans('#test', '001AAA'));
        $module = $this->createStub(ProtocolModuleInterface::class);
        $holder->setProtocolModule($module);
        self::assertNull($query->findMatchingBans('#test', '001AAA'));
        $provider = $this->createStubForIntersectionOfInterfaces([ProtocolModuleInterface::class, ChannelBanMatcherProvider::class]);
        self::assertInstanceOf(ProtocolModuleInterface::class, $provider);
        $holder->setProtocolModule($provider);
        self::assertNull($query->findMatchingBans('#test', '001AAA'));
        self::assertInstanceOf(ChannelBanMatcherProvider::class, $provider);
        $provider->method('getChannelBanMatcher')->willReturn(new InspIRCdChannelBanMatcher());
        self::assertNull($query->findMatchingBans('#test', '001AAA'));
        self::assertNull($query->findMatchingBans('#test', ''));
    }
}
