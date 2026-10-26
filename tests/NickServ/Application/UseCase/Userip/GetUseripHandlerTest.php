<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Userip;

use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\Userip\GetUserip;
use App\NickServ\Application\UseCase\Userip\GetUseripHandler;
use App\NickServ\Application\UseCase\Userip\GetUseripOutcome;
use App\NickServ\Application\UseCase\Userip\GetUseripResult;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
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
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::never())->method('findByNick');
        $handler = new GetUseripHandler($repository, new NickHistoryService($this->createStub(NickHistoryRepositoryInterface::class)));

        $resultNullIp = $handler->handle(new GetUserip(nickname: 'OfflineNick', actorNickname: 'Oper', occurredAt: new DateTimeImmutable('2026-09-06 UTC'), ip: null, hostname: 'host.net'));
        self::assertSame(GetUseripOutcome::NotOnline, $resultNullIp->outcome);
        self::assertSame('OfflineNick', $resultNullIp->nickname);

        $resultNullHost = $handler->handle(new GetUserip(nickname: 'OfflineNick', actorNickname: 'Oper', occurredAt: new DateTimeImmutable('2026-09-06 UTC'), ip: '1.2.3.4', hostname: null));
        self::assertSame(GetUseripOutcome::NotOnline, $resultNullHost->outcome);
        self::assertSame('OfflineNick', $resultNullHost->nickname);
    }

    #[Test]
    public function returnsSuccessWithIpAndHostname(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(50);
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('OnlineNick')->willReturn($account);
        $historyRepository = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepository->expects(self::once())->method('save')->with(self::callback(static fn (NickHistory $entry): bool => 50 === $entry->getNickId()
            && 'USERIP' === $entry->getAction()
            && 'Oper' === $entry->getPerformedBy()
            && null === $entry->getPerformedByNickId()
            && [] === $entry->getExtraData()));
        $handler = new GetUseripHandler($repository, new NickHistoryService($historyRepository));
        $result = $handler->handle(new GetUserip(
            nickname: 'OnlineNick',
            ip: '192.168.1.50',
            hostname: 'user.isp.com',
            actorNickname: 'Oper',
            occurredAt: new DateTimeImmutable('2026-09-06 UTC'),
        ));

        self::assertSame(GetUseripOutcome::Success, $result->outcome);
        self::assertSame('OnlineNick', $result->nickname);
        self::assertSame('192.168.1.50', $result->ip);
        self::assertSame('user.isp.com', $result->host);
    }

    #[Test]
    public function successfulQueryForUnregisteredNicknameDoesNotCreateHistory(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('Unregistered')->willReturn(null);
        $historyRepository = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepository->expects(self::never())->method('save');
        $handler = new GetUseripHandler($repository, new NickHistoryService($historyRepository));

        $result = $handler->handle(new GetUserip(
            nickname: 'Unregistered',
            actorNickname: 'Oper',
            occurredAt: new DateTimeImmutable('2026-09-06 UTC'),
            ip: '192.0.2.9',
            hostname: 'client.example',
        ));

        self::assertSame(GetUseripOutcome::Success, $result->outcome);
        self::assertSame('192.0.2.9', $result->ip);
    }
}
