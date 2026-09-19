<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\NickServ;

use App\MemoServ\Adapter\Out\NickServ\NickServMemoUserAccountAdapter;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServMemoUserAccountAdapter::class)]
final class NickServMemoUserAccountAdapterTest extends TestCase
{
    #[Test]
    public function findAccountByNickReturnsViewWhenFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findAccountByNick')
            ->with('Alice')
            ->willReturn(new NickAccountData(1, 'Alice', 'es'));

        $adapter = new NickServMemoUserAccountAdapter($query);

        $view = $adapter->findAccountByNick('Alice');

        self::assertNotNull($view);
        self::assertSame(1, $view->id);
        self::assertSame('Alice', $view->nickname);
        self::assertSame('es', $view->language);
    }

    #[Test]
    public function findAccountByNickReturnsNullWhenNotFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findAccountByNick')
            ->with('Bob')
            ->willReturn(null);

        $adapter = new NickServMemoUserAccountAdapter($query);

        self::assertNull($adapter->findAccountByNick('Bob'));
    }

    #[Test]
    public function findNicknameByIdReturnsNickWhenFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findNicknameById')
            ->with(42)
            ->willReturn('Charlie');

        $adapter = new NickServMemoUserAccountAdapter($query);

        self::assertSame('Charlie', $adapter->findNicknameById(42));
    }

    #[Test]
    public function findNicknameByIdReturnsNullWhenNotFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findNicknameById')
            ->with(999)
            ->willReturn(null);

        $adapter = new NickServMemoUserAccountAdapter($query);

        self::assertNull($adapter->findNicknameById(999));
    }

    #[Test]
    public function getLanguageReturnsLanguageFromQuery(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('getLanguage')
            ->with(10)
            ->willReturn('fr');

        $adapter = new NickServMemoUserAccountAdapter($query);

        self::assertSame('fr', $adapter->getLanguage(10));
    }
}
