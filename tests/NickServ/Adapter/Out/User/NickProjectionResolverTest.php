<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\Out\User\NickProjectionResolver;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(NickProjectionResolver::class)]
#[CoversClass(NickProjection::class)]
final class NickProjectionResolverTest extends TestCase
{
    #[Test]
    public function projectsAllFromScalarArrayDqlQueryWithoutHydratingEntities(): void
    {
        $suspendedUntil = new DateTimeImmutable('2026-10-01 12:00:00 UTC');
        $rows = [
            [
                'id' => 42,
                'nickname' => 'Alice',
                'passwordHash' => '$2y$12$hash',
                'vhost' => 'alice.example.test',
                'status' => NickStatus::Registered,
                'reason' => null,
                'suspendedUntil' => null,
                'automaticModes' => ['bot', 'strip-formatting'],
            ],
            [
                'id' => 7,
                'nickname' => 'BadNick',
                'passwordHash' => null,
                'vhost' => null,
                'status' => NickStatus::Forbidden->value,
                'reason' => 'abuse',
                'suspendedUntil' => null,
                'automaticModes' => null,
            ],
            [
                'id' => 8,
                'nickname' => 'PendingNick',
                'passwordHash' => 'hash',
                'vhost' => null,
                'status' => NickStatus::Pending,
                'reason' => null,
                'suspendedUntil' => null,
                'automaticModes' => [],
            ],
            [
                'id' => 9,
                'nickname' => 'Suspended',
                'passwordHash' => 'hash',
                'vhost' => null,
                'status' => NickStatus::Suspended,
                'reason' => 'abuse',
                'suspendedUntil' => $suspendedUntil,
                'automaticModes' => [],
            ],
            [
                'id' => 10,
                'nickname' => 'SuspendedStringDate',
                'passwordHash' => 'hash',
                'vhost' => null,
                'status' => NickStatus::Suspended->value,
                'reason' => 'spam',
                'suspendedUntil' => '2026-11-01 00:00:00 UTC',
                'automaticModes' => null,
            ],
            [
                'id' => 11,
                'nickname' => 'SuspendedInvalidDate',
                'passwordHash' => null,
                'vhost' => null,
                'status' => NickStatus::Suspended->value,
                'reason' => 'other',
                'suspendedUntil' => 12345,
                'automaticModes' => null,
            ],
        ];

        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getArrayResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())->method('select')->with('n.id, n.nickname, n.passwordHash, n.vhost, n.status, n.reason, n.suspendedUntil, n.automaticModes')->willReturnSelf();
        $qb->expects(self::once())->method('from')->with(RegisteredNick::class, 'n')->willReturnSelf();
        $qb->expects(self::once())->method('orderBy')->with('n.id', 'ASC')->willReturnSelf();
        $qb->expects(self::once())->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('createQueryBuilder')->willReturn($qb);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $resolver = new NickProjectionResolver($repository, $em);
        $all = $resolver->all();

        self::assertCount(6, $all);
        self::assertSame(42, $all[0]->id);
        self::assertSame('Alice', $all[0]->nickname);
        self::assertSame('$2y$12$hash', $all[0]->passwordHash);
        self::assertSame('alice.example.test', $all[0]->vhost);
        self::assertFalse($all[0]->forbidden);
        self::assertFalse($all[0]->suspended);
        self::assertFalse($all[0]->pendingVerification);
        self::assertSame(['bot', 'strip-formatting'], $all[0]->automaticModes);

        self::assertSame(7, $all[1]->id);
        self::assertTrue($all[1]->forbidden);
        self::assertSame('abuse', $all[1]->forbiddenReason);

        self::assertSame(8, $all[2]->id);
        self::assertTrue($all[2]->pendingVerification);

        self::assertSame(9, $all[3]->id);
        self::assertTrue($all[3]->suspended);
        self::assertSame('abuse', $all[3]->suspensionReason);
        self::assertSame($suspendedUntil, $all[3]->suspendedUntil);

        self::assertSame(10, $all[4]->id);
        self::assertTrue($all[4]->suspended);
        self::assertSame('spam', $all[4]->suspensionReason);
        self::assertSame(new DateTimeImmutable('2026-11-01 00:00:00 UTC')->getTimestamp(), $all[4]->suspendedUntil?->getTimestamp());

        self::assertSame(11, $all[5]->id);
        self::assertTrue($all[5]->suspended);
        self::assertSame('other', $all[5]->suspensionReason);
        self::assertNull($all[5]->suspendedUntil);
    }

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
    public function projectsTemporarySuspensionStateReasonAndExpiry(): void
    {
        $nick = RegisteredNick::createPending('Suspended', 'hash', 'suspended@example.test', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $nick->activate();
        $until = new DateTimeImmutable('2026-10-01 12:00:00 UTC');
        $nick->suspend('abuse', $until);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($nick, 9);

        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn($nick);

        $found = new NickProjectionResolver($repository)->findById(9);

        self::assertNotNull($found);
        self::assertProjection(new NickProjection(9, 'Suspended', 'hash', null, false, null, false, true, 'abuse', $until), $found);
    }

    #[Test]
    public function projectsPermanentSuspensionWithoutExpiry(): void
    {
        $nick = RegisteredNick::createPending('Permanent', 'hash', 'permanent@example.test', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $nick->activate();
        $nick->suspend('serious abuse');
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($nick, 10);

        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn($nick);

        $found = new NickProjectionResolver($repository)->findById(10);

        self::assertNotNull($found);
        self::assertProjection(new NickProjection(10, 'Permanent', 'hash', null, false, null, false, true, 'serious abuse'), $found);
    }

    #[Test]
    public function returnsNullWhenTheNickDoesNotExist(): void
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);

        self::assertNull(new NickProjectionResolver($repository)->findById(999));
    }

    #[Test]
    public function projectsSemanticAutomaticPreferencesForBothIncrementalAndFullConsumers(): void
    {
        $nick = RegisteredNick::createActive('Pedro', 'hash', null, 'en', new DateTimeImmutable());
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($nick, 12);
        $nick->changeAutomaticModes(new AutomaticModeProfile(['bot', 'strip-formatting']));
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('all')->willReturn([$nick]);
        $repository->method('findById')->willReturn($nick);
        $resolver = new NickProjectionResolver($repository);
        self::assertSame(['bot', 'strip-formatting'], $resolver->findById(12)?->automaticModes);
        self::assertSame(['bot', 'strip-formatting'], $resolver->all()[0]->automaticModes);
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
        self::assertSame($expected->suspended, $actual->suspended);
        self::assertSame($expected->suspensionReason, $actual->suspensionReason);
        self::assertSame($expected->suspendedUntil, $actual->suspendedUntil);
    }
}
