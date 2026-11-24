<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\Out\Projection\DoctrineProtectedGlineSubjects;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineProtectedGlineSubjects::class)]
final class DoctrineProtectedGlineSubjectsTest extends TestCase
{
    #[Test]
    public function mergesRootsAndOperatorsCaseInsensitivelyAndSkipsMissingAccounts(): void
    {
        $roots = $this->createStub(RootIdentityRegistry::class);
        $roots->method('allNicknames')->willReturn(['Root', 'SecondRoot']);
        $role = OperRole::create('OPER');
        $addedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $duplicate = OperIrcop::create($addedAt, 1, $role);
        $operator = OperIrcop::create($addedAt, 2, $role);
        $missing = OperIrcop::create($addedAt, 3, $role);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findAll')->willReturn([$duplicate, $operator, $missing, $duplicate]);
        $accounts = $this->createMock(NickAccountQuery::class);
        $accounts->expects(self::once())->method('findNicknamesByIds')->with([1, 2, 3])->willReturn([
            1 => 'root',
            2 => 'NetAdmin',
        ]);

        self::assertSame(
            ['Root', 'SecondRoot', 'NetAdmin'],
            new DoctrineProtectedGlineSubjects($roots, $operators, $accounts)->nicknames(),
        );
    }

    #[Test]
    public function keepsEveryConfiguredRootSpellingAndOperatorsNotAlreadyListed(): void
    {
        $roots = $this->createStub(RootIdentityRegistry::class);
        $roots->method('allNicknames')->willReturn(['admin[', 'admin{']);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findAll')->willReturn([
            OperIrcop::create(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 1, OperRole::create('OPER')),
            OperIrcop::create(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 2, OperRole::create('OPER')),
        ]);
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findNicknamesByIds')->willReturn([1 => 'Admin[', 2 => 'Oper^']);

        self::assertSame(
            ['admin[', 'admin{', 'Oper^'],
            new DoctrineProtectedGlineSubjects($roots, $operators, $accounts)->nicknames(),
        );
    }
}
