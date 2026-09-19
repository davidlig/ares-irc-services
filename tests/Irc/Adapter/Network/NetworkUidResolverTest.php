<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\NetworkUidResolver;
use App\Irc\Application\Port\In\UidResolverInterface;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetworkUidResolver::class)]
final class NetworkUidResolverTest extends TestCase
{
    private MockObject&NetworkUserRepositoryInterface $userRepository;

    private UidResolverInterface $resolver;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(NetworkUserRepositoryInterface::class);
        $this->resolver = new NetworkUidResolver($this->userRepository);
    }

    #[Test]
    public function resolvesUidToNick(): void
    {
        $user = $this->createStub(NetworkUser::class);
        $user->method('getNick')->willReturn(new Nick('davidlig'));

        $this->userRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(self::callback(static fn (Uid $uid): bool => '994AAAGUW' === $uid->value))
            ->willReturn($user);

        self::assertSame('davidlig', $this->resolver->resolveUidToNick('994AAAGUW'));
    }

    #[Test]
    public function returnsNullWhenUidNotFound(): void
    {
        $this->userRepository
            ->expects(self::once())
            ->method('findByUid')
            ->willReturn(null);

        self::assertNull($this->resolver->resolveUidToNick('994ZZZZZZ'));
    }

    #[Test]
    public function returnsNullForNonUidString(): void
    {
        $this->userRepository
            ->expects(self::never())
            ->method('findByUid');

        self::assertNull($this->resolver->resolveUidToNick('ChanServ'));
    }

    #[Test]
    public function returnsNullForServerSid(): void
    {
        $this->userRepository
            ->expects(self::never())
            ->method('findByUid');

        self::assertNull($this->resolver->resolveUidToNick('994'));
    }

    #[Test]
    public function returnsNullForEmptyString(): void
    {
        $this->userRepository
            ->expects(self::never())
            ->method('findByUid');

        self::assertNull($this->resolver->resolveUidToNick(''));
    }
}
