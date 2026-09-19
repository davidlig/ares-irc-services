<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\NickServ;

use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\Out\NickServ\NickServOperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorAccountData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServOperatorAccountLookup::class)]
#[CoversClass(OperatorAccountData::class)]
final class NickServOperatorAccountLookupTest extends TestCase
{
    #[Test]
    public function delegatesIdentityLookupsToThePublicNickservQuery(): void
    {
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findIdByNick')->willReturn(42);
        $accounts->method('findNicknameById')->willReturn('Alice');
        $lookup = new NickServOperatorAccountLookup($accounts);

        self::assertSame(42, $lookup->findIdByNickname('Alice'));
        self::assertSame('Alice', $lookup->findNicknameById(42));
    }

    #[Test]
    public function mapsAnExistingAccountToTheOperServBoundary(): void
    {
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findAccountByNick')->willReturn(
            new NickAccountData(42, 'Alice', 'es', registered: false),
        );

        $account = new NickServOperatorAccountLookup($accounts)->findByNickname('Alice');

        self::assertInstanceOf(OperatorAccountData::class, $account);
        self::assertSame(42, $account->id);
        self::assertSame('Alice', $account->nickname);
        self::assertFalse($account->registered);
    }

    #[Test]
    public function preservesAMissingAccount(): void
    {
        $accounts = $this->createStub(NickAccountQuery::class);

        self::assertNull(new NickServOperatorAccountLookup($accounts)->findByNickname('Missing'));
    }
}
