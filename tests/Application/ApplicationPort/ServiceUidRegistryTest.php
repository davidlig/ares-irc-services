<?php

declare(strict_types=1);

namespace App\Tests\Application\ApplicationPort;

use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\ApplicationPort\ServiceUidRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceUidRegistry::class)]
final class ServiceUidRegistryTest extends TestCase
{
    #[Test]
    public function getUidReturnsNullForUnknownService(): void
    {
        $registry = ServiceUidRegistry::fromIterable([]);

        self::assertNull($registry->getUid('unknown'));
    }

    #[Test]
    public function getUidReturnsUidForKnownService(): void
    {
        $provider = new class('nickserv', 'NickServ', '002AAAAAA') implements ServiceUidProviderInterface {
            public function __construct(private string $key, private string $nick, private string $uid)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }

            public function getUid(): string
            {
                return $this->uid;
            }
        };

        $registry = ServiceUidRegistry::fromIterable([$provider]);

        self::assertSame('002AAAAAA', $registry->getUid('nickserv'));
    }

    #[Test]
    public function getUidByNicknameReturnsNullForUnknownNickname(): void
    {
        $registry = ServiceUidRegistry::fromIterable([]);

        self::assertNull($registry->getUidByNickname('Unknown'));
    }

    #[Test]
    public function getUidByNicknameReturnsUidForKnownNickname(): void
    {
        $provider = new class('nickserv', 'NickServ', '002AAAAAA') implements ServiceUidProviderInterface {
            public function __construct(private string $key, private string $nick, private string $uid)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }

            public function getUid(): string
            {
                return $this->uid;
            }
        };

        $registry = ServiceUidRegistry::fromIterable([$provider]);

        self::assertSame('002AAAAAA', $registry->getUidByNickname('NickServ'));
        self::assertSame('002AAAAAA', $registry->getUidByNickname('nickserv'));
        self::assertSame('002AAAAAA', $registry->getUidByNickname('NICKSERV'));
    }

    #[Test]
    public function fromIterableWithMultipleProviders(): void
    {
        $nickserv = new class('nickserv', 'NickServ', '002AAAAAA') implements ServiceUidProviderInterface {
            public function __construct(private string $key, private string $nick, private string $uid)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }

            public function getUid(): string
            {
                return $this->uid;
            }
        };

        $chanserv = new class('chanserv', 'ChanServ', '002BBBBBB') implements ServiceUidProviderInterface {
            public function __construct(private string $key, private string $nick, private string $uid)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }

            public function getUid(): string
            {
                return $this->uid;
            }
        };

        $registry = ServiceUidRegistry::fromIterable([$nickserv, $chanserv]);

        self::assertSame('002AAAAAA', $registry->getUid('nickserv'));
        self::assertSame('002BBBBBB', $registry->getUid('chanserv'));
        self::assertSame('002AAAAAA', $registry->getUidByNickname('NickServ'));
        self::assertSame('002BBBBBB', $registry->getUidByNickname('ChanServ'));
    }
}
