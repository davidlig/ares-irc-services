<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\Out\Legacy\LegacyProtectedGlineSubjects;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyProtectedGlineSubjects::class)]
final class LegacyProtectedGlineSubjectsTest extends TestCase
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
            new LegacyProtectedGlineSubjects($roots, $operators, $accounts)->nicknames(),
        );
    }
}
