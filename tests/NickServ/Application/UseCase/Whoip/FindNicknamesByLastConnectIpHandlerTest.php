<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Whoip;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\UseCase\Whoip\FindNicknamesByLastConnectIp;
use App\NickServ\Application\UseCase\Whoip\FindNicknamesByLastConnectIpHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindNicknamesByLastConnectIp::class)]
#[CoversClass(FindNicknamesByLastConnectIpHandler::class)]
final class FindNicknamesByLastConnectIpHandlerTest extends TestCase
{
    #[Test]
    public function returnsEveryNicknameForCanonicalIpv4(): void
    {
        $nicknames = ['alpha', 'Bravo', 'Zebra'];
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findNicknamesByLastConnectIp')
            ->with('203.0.113.7')
            ->willReturn($nicknames);

        $result = new FindNicknamesByLastConnectIpHandler($repository)
            ->handle(new FindNicknamesByLastConnectIp('203.0.113.7'));

        self::assertSame($nicknames, $result);
    }

    #[Test]
    public function returnsEmptyWhenCanonicalIpv6HasNoMatches(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findNicknamesByLastConnectIp')
            ->with('2001:db8::5')
            ->willReturn([]);

        $result = new FindNicknamesByLastConnectIpHandler($repository)
            ->handle(new FindNicknamesByLastConnectIp('2001:db8::5'));

        self::assertSame([], $result);
    }
}
