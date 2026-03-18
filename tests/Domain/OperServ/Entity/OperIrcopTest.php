<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\Entity;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OperIrcop::class)]
final class OperIrcopTest extends TestCase
{
    #[Test]
    public function createWithAllParameters(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 42,
            role: $role,
            addedById: 10,
            reason: 'Promoted to operator',
        );

        self::assertSame(42, $ircop->getNickId());
        self::assertSame($role, $ircop->getRole());
        self::assertSame(10, $ircop->getAddedById());
        self::assertSame('Promoted to operator', $ircop->getReason());
        self::assertInstanceOf(DateTimeImmutable::class, $ircop->getAddedAt());
    }

    #[Test]
    public function createWithMinimalParameters(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 100,
            role: $role,
        );

        self::assertSame(100, $ircop->getNickId());
        self::assertSame($role, $ircop->getRole());
        self::assertNull($ircop->getAddedById());
        self::assertNull($ircop->getReason());
    }

    #[Test]
    public function createWithNullAddedById(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 1,
            role: $role,
            addedById: null,
            reason: 'System assignment',
        );

        self::assertNull($ircop->getAddedById());
        self::assertSame('System assignment', $ircop->getReason());
    }

    #[Test]
    public function createWithNullReason(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 5,
            role: $role,
            addedById: 99,
            reason: null,
        );

        self::assertSame(99, $ircop->getAddedById());
        self::assertNull($ircop->getReason());
    }

    #[Test]
    public function getNickIdReturnsCorrectValue(): void
    {
        $role = $this->createStub(OperRole::class);
        $ircop = OperIrcop::create(nickId: 777, role: $role);

        self::assertSame(777, $ircop->getNickId());
    }

    #[Test]
    public function getRoleReturnsCorrectInstance(): void
    {
        $role = $this->createStub(OperRole::class);
        $ircop = OperIrcop::create(nickId: 1, role: $role);

        self::assertSame($role, $ircop->getRole());
    }

    #[Test]
    public function changeRoleUpdatesRole(): void
    {
        $originalRole = $this->createStub(OperRole::class);
        $newRole = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(nickId: 1, role: $originalRole);

        self::assertSame($originalRole, $ircop->getRole());

        $ircop->changeRole($newRole);

        self::assertSame($newRole, $ircop->getRole());
    }

    #[Test]
    public function getAddedAtReturnsDateTimeImmutable(): void
    {
        $role = $this->createStub(OperRole::class);
        $beforeCreate = new DateTimeImmutable();

        $ircop = OperIrcop::create(nickId: 1, role: $role);

        $afterCreate = new DateTimeImmutable();

        self::assertInstanceOf(DateTimeImmutable::class, $ircop->getAddedAt());
        self::assertGreaterThanOrEqual($beforeCreate, $ircop->getAddedAt());
        self::assertLessThanOrEqual($afterCreate, $ircop->getAddedAt());
    }

    #[Test]
    public function getAddedByIdReturnsCorrectValue(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 1,
            role: $role,
            addedById: 500,
        );

        self::assertSame(500, $ircop->getAddedById());
    }

    #[Test]
    public function getReasonReturnsCorrectValue(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 1,
            role: $role,
            reason: 'Trusted user',
        );

        self::assertSame('Trusted user', $ircop->getReason());
    }

    #[Test]
    public function setReasonUpdatesReason(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 1,
            role: $role,
            reason: 'Original reason',
        );

        self::assertSame('Original reason', $ircop->getReason());

        $ircop->setReason('Updated reason');

        self::assertSame('Updated reason', $ircop->getReason());
    }

    #[Test]
    public function setReasonCanClearReason(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 1,
            role: $role,
            reason: 'Will be cleared',
        );

        self::assertSame('Will be cleared', $ircop->getReason());

        $ircop->setReason(null);

        self::assertNull($ircop->getReason());
    }

    #[Test]
    public function setReasonCanSetReasonOnNullReason(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(
            nickId: 1,
            role: $role,
            reason: null,
        );

        self::assertNull($ircop->getReason());

        $ircop->setReason('Now has reason');

        self::assertSame('Now has reason', $ircop->getReason());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $role = $this->createStub(OperRole::class);

        $ircop = OperIrcop::create(nickId: 1, role: $role);

        $reflection = new ReflectionClass($ircop);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($ircop, 999);

        self::assertSame(999, $ircop->getId());
    }
}
