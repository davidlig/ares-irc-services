<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\NickServ\Adapter\Out\Security\OperIrcopForcedVhostChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperIrcopForcedVhostChecker::class)]
final class OperIrcopForcedVhostCheckerTest extends TestCase
{
    #[Test]
    public function returnsFalseWhenNoIrcopFound(): void
    {
        $repo = $this->createStub(OperIrcopRepositoryInterface::class);
        $repo->method('findByNickId')->willReturn(null);

        $checker = new OperIrcopForcedVhostChecker($repo);

        self::assertFalse($checker->hasForcedVhost(123));
    }

    #[Test]
    public function returnsFalseWhenRolePatternIsNull(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn(null);
        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $repo = $this->createStub(OperIrcopRepositoryInterface::class);
        $repo->method('findByNickId')->willReturn($ircop);

        $checker = new OperIrcopForcedVhostChecker($repo);

        self::assertFalse($checker->hasForcedVhost(123));
    }

    #[Test]
    public function returnsFalseWhenRolePatternIsEmpty(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('');
        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $repo = $this->createStub(OperIrcopRepositoryInterface::class);
        $repo->method('findByNickId')->willReturn($ircop);

        $checker = new OperIrcopForcedVhostChecker($repo);

        self::assertFalse($checker->hasForcedVhost(123));
    }

    #[Test]
    public function returnsTrueWhenRoleHasValidPattern(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('staff.example.com');
        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $repo = $this->createStub(OperIrcopRepositoryInterface::class);
        $repo->method('findByNickId')->willReturn($ircop);

        $checker = new OperIrcopForcedVhostChecker($repo);

        self::assertTrue($checker->hasForcedVhost(123));
    }
}
