<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Userip;

use App\NickServ\Application\UseCase\Userip\GetUserip;
use App\NickServ\Application\UseCase\Userip\GetUseripHandler;
use App\NickServ\Application\UseCase\Userip\GetUseripOutcome;
use App\NickServ\Application\UseCase\Userip\GetUseripResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetUseripHandler::class)]
#[CoversClass(GetUserip::class)]
#[CoversClass(GetUseripResult::class)]
final class GetUseripHandlerTest extends TestCase
{
    #[Test]
    public function returnsNotOnlineWhenIpOrHostnameIsNull(): void
    {
        $handler = new GetUseripHandler();

        $resultNullIp = $handler->handle(new GetUserip(nickname: 'OfflineNick', ip: null, hostname: 'host.net'));
        self::assertSame(GetUseripOutcome::NotOnline, $resultNullIp->outcome);
        self::assertSame('OfflineNick', $resultNullIp->nickname);

        $resultNullHost = $handler->handle(new GetUserip(nickname: 'OfflineNick', ip: '1.2.3.4', hostname: null));
        self::assertSame(GetUseripOutcome::NotOnline, $resultNullHost->outcome);
        self::assertSame('OfflineNick', $resultNullHost->nickname);
    }

    #[Test]
    public function returnsSuccessWithIpAndHostname(): void
    {
        $handler = new GetUseripHandler();
        $result = $handler->handle(new GetUserip(nickname: 'OnlineNick', ip: '192.168.1.50', hostname: 'user.isp.com'));

        self::assertSame(GetUseripOutcome::Success, $result->outcome);
        self::assertSame('OnlineNick', $result->nickname);
        self::assertSame('192.168.1.50', $result->ip);
        self::assertSame('user.isp.com', $result->host);
    }
}
