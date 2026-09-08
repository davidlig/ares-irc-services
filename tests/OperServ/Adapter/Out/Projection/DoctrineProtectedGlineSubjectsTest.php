<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\Out\Projection\DoctrineProtectedGlineSubjects;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Domain\Entity\OperIrcop;
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
        $duplicate = $this->createStub(OperIrcop::class);
        $duplicate->method('getNickId')->willReturn(1);
        $operator = $this->createStub(OperIrcop::class);
        $operator->method('getNickId')->willReturn(2);
        $missing = $this->createStub(OperIrcop::class);
        $missing->method('getNickId')->willReturn(3);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findAll')->willReturn([$duplicate, $operator, $missing]);
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findNicknameById')->willReturnMap([
            [1, 'root'],
            [2, 'NetAdmin'],
            [3, null],
        ]);

        self::assertSame(
            ['Root', 'SecondRoot', 'NetAdmin'],
            new DoctrineProtectedGlineSubjects($roots, $operators, $accounts)->nicknames(),
        );
    }
}
