<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\NickServ;

use App\ChanServ\Adapter\Out\NickServ\NickServChanUserAccountAdapter;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServChanUserAccountAdapter::class)]
final class NickServChanUserAccountAdapterTest extends TestCase
{
    #[Test]
    public function findAccountByNickReturnsViewWhenFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $data = new NickAccountData(
            id: 42,
            nickname: 'Alice',
            language: 'es',
            timezone: 'Europe/Madrid',
            registered: true,
            suspended: false,
            email: 'alice@example.com',
        );
        $query->expects(self::once())
            ->method('findAccountByNick')
            ->with('Alice')
            ->willReturn($data);

        $adapter = new NickServChanUserAccountAdapter($query);
        $view = $adapter->findAccountByNick('Alice');

        self::assertNotNull($view);
        self::assertSame(42, $view->id);
        self::assertSame('Alice', $view->nickname);
        self::assertSame('es', $view->language);
        self::assertSame('Europe/Madrid', $view->timezone);
        self::assertTrue($view->registered);
        self::assertFalse($view->suspended);
        self::assertSame('alice@example.com', $view->email);
    }

    #[Test]
    public function findAccountByNickReturnsNullWhenNotFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findAccountByNick')
            ->with('Bob')
            ->willReturn(null);

        $adapter = new NickServChanUserAccountAdapter($query);
        $view = $adapter->findAccountByNick('Bob');

        self::assertNull($view);
    }

    #[Test]
    public function findAccountByIdReturnsViewWhenFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $data = new NickAccountData(
            id: 99,
            nickname: 'Charlie',
            language: 'en',
            timezone: 'UTC',
            registered: false,
            suspended: true,
            email: null,
        );
        $query->expects(self::once())
            ->method('findAccountById')
            ->with(99)
            ->willReturn($data);

        $adapter = new NickServChanUserAccountAdapter($query);
        $view = $adapter->findAccountById(99);

        self::assertNotNull($view);
        self::assertSame(99, $view->id);
        self::assertSame('Charlie', $view->nickname);
        self::assertSame('en', $view->language);
        self::assertSame('UTC', $view->timezone);
        self::assertFalse($view->registered);
        self::assertTrue($view->suspended);
        self::assertNull($view->email);
    }

    #[Test]
    public function findAccountByIdReturnsNullWhenNotFound(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findAccountById')
            ->with(123)
            ->willReturn(null);

        $adapter = new NickServChanUserAccountAdapter($query);
        $view = $adapter->findAccountById(123);

        self::assertNull($view);
    }

    #[Test]
    public function findIdByNickDelegatesToQuery(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findIdByNick')
            ->with('Alice')
            ->willReturn(42);

        $adapter = new NickServChanUserAccountAdapter($query);
        self::assertSame(42, $adapter->findIdByNick('Alice'));
    }

    #[Test]
    public function findNicknameByIdDelegatesToQuery(): void
    {
        $query = $this->createMock(NickAccountQuery::class);
        $query->expects(self::once())
            ->method('findNicknameById')
            ->with(42)
            ->willReturn('Alice');

        $adapter = new NickServChanUserAccountAdapter($query);
        self::assertSame('Alice', $adapter->findNicknameById(42));
    }
}
