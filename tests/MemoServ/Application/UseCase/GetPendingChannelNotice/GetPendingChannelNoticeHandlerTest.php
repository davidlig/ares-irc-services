<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\GetPendingChannelNotice;

use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNotice;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeHandler;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeOutcome;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetPendingChannelNotice::class)]
#[CoversClass(GetPendingChannelNoticeHandler::class)]
#[CoversClass(GetPendingChannelNoticeResult::class)]
final class GetPendingChannelNoticeHandlerTest extends TestCase
{
    #[Test]
    public function returnsNoNoticeWhenChannelDoesNotExist(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->expects(self::once())->method('findChannelByName')->with('#mixed')->willReturn(null);
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::never())->method('isEnabledForChannel');

        $result = $this->handler(
            $channelPort,
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $settingsRepository,
        )->handle(new GetPendingChannelNotice('001ABC', 'User', true, '#MiXeD'));

        self::assertSame(GetPendingChannelNoticeOutcome::NoNotice, $result->outcome);
    }

    #[Test]
    public function returnsNoNoticeWhenChannelMemosAreDisabled(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::once())->method('isEnabledForChannel')->with(5)->willReturn(false);
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::never())->method('countUnreadByTargetChannel');

        $result = $this->handler(
            $channelPort,
            $this->createStub(MemoUserAccountPort::class),
            $memoRepository,
            $settingsRepository,
        )->handle(new GetPendingChannelNotice('001ABC', 'User', true, '#ares'));

        self::assertSame(GetPendingChannelNoticeOutcome::NoNotice, $result->outcome);
    }

    #[Test]
    public function returnsNoNoticeWhenThereAreNoUnreadMemos(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $settingsRepository = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepository->method('isEnabledForChannel')->willReturn(true);
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetChannel')->with(5)->willReturn(0);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::never())->method('findAccountByNick');

        $result = $this->handler($channelPort, $userAccountPort, $memoRepository, $settingsRepository)
            ->handle(new GetPendingChannelNotice('001ABC', 'User', true, '#ares'));

        self::assertSame(GetPendingChannelNoticeOutcome::NoNotice, $result->outcome);
    }

    #[Test]
    public function returnsNoNoticeWhenNickIsNotRegistered(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->expects(self::never())->method('canReadChannelMemos');
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(null);

        $result = $this->handlerWithUnreadMemos($channelPort, $userAccountPort)
            ->handle(new GetPendingChannelNotice('001ABC', 'Unknown', true, '#ares'));

        self::assertSame(GetPendingChannelNoticeOutcome::NoNotice, $result->outcome);
    }

    #[Test]
    public function returnsNoNoticeWhenUserIsNotIdentified(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->expects(self::never())->method('canReadChannelMemos');
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(10, 'User', 'es'));

        $result = $this->handlerWithUnreadMemos($channelPort, $userAccountPort)
            ->handle(new GetPendingChannelNotice('001ABC', 'User', false, '#ares'));

        self::assertSame(GetPendingChannelNoticeOutcome::NoNotice, $result->outcome);
    }

    #[Test]
    public function returnsNoNoticeWhenUserCannotReadChannelMemos(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('canReadChannelMemos')->willReturn(false);
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(10, 'User', 'es'));

        $result = $this->handlerWithUnreadMemos($channelPort, $userAccountPort)
            ->handle(new GetPendingChannelNotice('001ABC', 'User', true, '#ares'));

        self::assertSame(GetPendingChannelNoticeOutcome::NoNotice, $result->outcome);
    }

    #[Test]
    public function returnsSemanticNoticeDataWhenUserCanReadUnreadChannelMemos(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->expects(self::once())->method('findChannelByName')->with('#mixed')->willReturn(new MemoChannelView(5, '#Mixed'));
        $channelPort->expects(self::once())->method('canReadChannelMemos')->with(5, 10)->willReturn(true);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('findAccountByNick')->with('User')->willReturn(new MemoAccountView(10, 'User', 'es'));

        $result = $this->handlerWithUnreadMemos($channelPort, $userAccountPort)
            ->handle(new GetPendingChannelNotice('001ABC', 'User', true, '#MiXeD'));

        self::assertSame(GetPendingChannelNoticeOutcome::PendingMemos, $result->outcome);
        self::assertSame('001ABC', $result->uid);
        self::assertSame('#MiXeD', $result->channelName);
        self::assertSame(2, $result->unreadCount);
        self::assertSame('es', $result->language);
    }

    private function handlerWithUnreadMemos(
        MemoChannelPort $channelPort,
        MemoUserAccountPort $userAccountPort,
    ): GetPendingChannelNoticeHandler {
        $memoRepository = $this->createStub(MemoRepositoryInterface::class);
        $memoRepository->method('countUnreadByTargetChannel')->willReturn(2);
        $settingsRepository = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepository->method('isEnabledForChannel')->willReturn(true);

        return $this->handler($channelPort, $userAccountPort, $memoRepository, $settingsRepository);
    }

    private function handler(
        MemoChannelPort $channelPort,
        MemoUserAccountPort $userAccountPort,
        MemoRepositoryInterface $memoRepository,
        MemoSettingsRepositoryInterface $settingsRepository,
    ): GetPendingChannelNoticeHandler {
        return new GetPendingChannelNoticeHandler(
            $channelPort,
            $userAccountPort,
            $memoRepository,
            $settingsRepository,
        );
    }
}
