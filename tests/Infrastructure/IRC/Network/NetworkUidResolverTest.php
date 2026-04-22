<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Application\Port\UidResolverInterface;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\NetworkUidResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetworkUidResolver::class)]
final class NetworkUidResolverTest extends TestCase
{
    private NetworkUserRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $userRepository;

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
