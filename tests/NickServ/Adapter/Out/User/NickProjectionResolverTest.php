<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\Out\User\NickProjectionResolver;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(NickProjectionResolver::class)]
#[CoversClass(NickProjection::class)]
final class NickProjectionResolverTest extends TestCase
{
    #[Test]
    public function projectsAllAndOneRegisteredNickWithoutExposingTheDomainEntity(): void
    {
        $nick = RegisteredNick::createPending(
            'Alice',
            '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe',
            'alice@example.test',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );
        $nick->activate();
        $nick->changeVhost('alice.example.test');
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($nick, 42);

        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('all')->willReturn([$nick]);
        $repository->method('findById')->willReturn($nick);
        $resolver = new NickProjectionResolver($repository);

        $expected = new NickProjection(42, 'Alice', $nick->getPasswordHash(), 'alice.example.test', false, null, false);
        $all = $resolver->all();
        self::assertCount(1, $all);
        self::assertProjection($expected, $all[0]);

        $found = $resolver->findById(42);
        self::assertNotNull($found);
        self::assertProjection($expected, $found);
    }

    #[Test]
    public function projectsForbiddenNickWithItsReason(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'abuse');
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($nick, 7);

        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn($nick);

        $found = new NickProjectionResolver($repository)->findById(7);

        self::assertNotNull($found);
        self::assertTrue($found->forbidden);
        self::assertSame('abuse', $found->forbiddenReason);
        self::assertNull($found->passwordHash);
        self::assertFalse($found->pendingVerification);
    }

    #[Test]
    public function projectsPendingVerificationState(): void
    {
        $nick = RegisteredNick::createPending(
            'PendingNick',
            '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe',
            'pending@example.test',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($nick, 8);

        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn($nick);

        $found = new NickProjectionResolver($repository)->findById(8);

        self::assertNotNull($found);
        self::assertTrue($found->pendingVerification);
    }

    #[Test]
    public function returnsNullWhenTheNickDoesNotExist(): void
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);

        self::assertNull(new NickProjectionResolver($repository)->findById(999));
    }

    private static function assertProjection(NickProjection $expected, NickProjection $actual): void
    {
        self::assertSame($expected->id, $actual->id);
        self::assertSame($expected->nickname, $actual->nickname);
        self::assertSame($expected->passwordHash, $actual->passwordHash);
        self::assertSame($expected->vhost, $actual->vhost);
        self::assertSame($expected->forbidden, $actual->forbidden);
        self::assertSame($expected->forbiddenReason, $actual->forbiddenReason);
        self::assertSame($expected->pendingVerification, $actual->pendingVerification);
    }
}
