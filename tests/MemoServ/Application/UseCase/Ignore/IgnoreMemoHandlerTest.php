<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\Ignore;

use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemo;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoAction;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoHandler;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoOutcome;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoResult;
use App\MemoServ\Domain\Entity\MemoIgnore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IgnoreMemoHandler::class)]
#[CoversClass(IgnoreMemo::class)]
#[CoversClass(IgnoreMemoResult::class)]
final class IgnoreMemoHandlerTest extends TestCase
{
    private MemoUserAccountPort $userAccountPort;

    private MemoChannelPort $channelPort;

    private MemoIgnoreRepositoryInterface $memoIgnoreRepository;

    protected function setUp(): void
    {
        $this->userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoIgnoreRepository = $this->createStub(MemoIgnoreRepositoryInterface::class);
    }

    private function createHandler(): IgnoreMemoHandler
    {
        return new IgnoreMemoHandler(
            $this->userAccountPort,
            $this->channelPort,
            $this->memoIgnoreRepository,
            ignoreListLimitNick: 5,
            ignoreListLimitChannel: 10,
        );
    }

    #[Test]
    public function returnsChannelNotRegisteredWhenChannelNotFound(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(null);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, '#unknown', 'Bob'));

        self::assertSame(IgnoreMemoOutcome::ChannelNotRegistered, $result->outcome);
        self::assertSame('#unknown', $result->channelName);
    }

    #[Test]
    public function addNickIgnoreSuccessfully(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetNickAndIgnored')->willReturn(null);
        $memoIgnore->method('countByTargetNick')->willReturn(0);
        $memoIgnore->expects(self::once())->method('save')->with(self::isInstanceOf(MemoIgnore::class));
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, null, 'Bob'));

        self::assertSame(IgnoreMemoOutcome::AddedNick, $result->outcome);
        self::assertSame('Bob', $result->targetNick);
    }

    #[Test]
    public function addNickReturnsNickNotRegistered(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(null);
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, null, 'Unknown'));

        self::assertSame(IgnoreMemoOutcome::NickNotRegistered, $result->outcome);
    }

    #[Test]
    public function addNickReturnsCannotIgnoreSelf(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(1, 'Alice', 'en'));
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, null, 'Alice'));

        self::assertSame(IgnoreMemoOutcome::CannotIgnoreSelf, $result->outcome);
    }

    #[Test]
    public function addNickReturnsAlreadyIgnored(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetNickAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, null, 'Bob'));

        self::assertSame(IgnoreMemoOutcome::AlreadyIgnored, $result->outcome);
    }

    #[Test]
    public function addNickReturnsLimitReached(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetNickAndIgnored')->willReturn(null);
        $memoIgnore->method('countByTargetNick')->willReturn(5);
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, null, 'Bob'));

        self::assertSame(IgnoreMemoOutcome::LimitReached, $result->outcome);
    }

    #[Test]
    public function addChannelIgnoreSuccessfully(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(10, '#Ares'));
        $channelPort->expects(self::once())->method('requireManageAccess')->with(10, 1, '#ares', 'IGNORE');
        $this->channelPort = $channelPort;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetChannelAndIgnored')->willReturn(null);
        $memoIgnore->method('countByTargetChannel')->willReturn(0);
        $memoIgnore->expects(self::once())->method('save')->with(self::isInstanceOf(MemoIgnore::class));
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, '#ares', 'Bob'));

        self::assertSame(IgnoreMemoOutcome::AddedChannel, $result->outcome);
        self::assertSame('#Ares', $result->channelName);
        self::assertSame('Bob', $result->targetNick);
    }

    #[Test]
    public function addChannelReturnsAlreadyIgnored(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(10, '#Ares'));
        $this->channelPort = $channelPort;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetChannelAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, '#ares', 'Bob'));

        self::assertSame(IgnoreMemoOutcome::AlreadyIgnored, $result->outcome);
    }

    #[Test]
    public function addChannelReturnsLimitReached(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(10, '#Ares'));
        $this->channelPort = $channelPort;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetChannelAndIgnored')->willReturn(null);
        $memoIgnore->method('countByTargetChannel')->willReturn(10);
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Add, '#ares', 'Bob'));

        self::assertSame(IgnoreMemoOutcome::LimitReached, $result->outcome);
    }

    #[Test]
    public function delNickIgnoreSuccessfully(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $ignore = new MemoIgnore(1, null, 2);
        $memoIgnore = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetNickAndIgnored')->willReturn($ignore);
        $memoIgnore->expects(self::once())->method('delete')->with($ignore);
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Del, null, 'Bob'));

        self::assertSame(IgnoreMemoOutcome::DeletedNick, $result->outcome);
        self::assertSame('Bob', $result->targetNick);
    }

    #[Test]
    public function delNickReturnsNotIgnored(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetNickAndIgnored')->willReturn(null);
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Del, null, 'Bob'));

        self::assertSame(IgnoreMemoOutcome::NotIgnored, $result->outcome);
    }

    #[Test]
    public function delNickReturnsNickNotRegistered(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(null);
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Del, null, 'Unknown'));

        self::assertSame(IgnoreMemoOutcome::NickNotRegistered, $result->outcome);
    }

    #[Test]
    public function delChannelIgnoreSuccessfully(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(10, '#Ares'));
        $channelPort->expects(self::once())->method('requireManageAccess')->with(10, 1, '#ares', 'IGNORE');
        $this->channelPort = $channelPort;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $ignore = new MemoIgnore(null, 10, 2);
        $memoIgnore = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetChannelAndIgnored')->willReturn($ignore);
        $memoIgnore->expects(self::once())->method('delete')->with($ignore);
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Del, '#ares', 'Bob'));

        self::assertSame(IgnoreMemoOutcome::DeletedChannel, $result->outcome);
        self::assertSame('Bob', $result->targetNick);
        self::assertSame('#Ares', $result->channelName);
    }

    #[Test]
    public function delChannelReturnsNotIgnored(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(10, '#Ares'));
        $this->channelPort = $channelPort;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetChannelAndIgnored')->willReturn(null);
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::Del, '#ares', 'Bob'));

        self::assertSame(IgnoreMemoOutcome::NotIgnored, $result->outcome);
    }

    #[Test]
    public function listNickIgnoresSuccessfully(): void
    {
        $ignore1 = new MemoIgnore(1, null, 2);
        $ignore2 = new MemoIgnore(1, null, 3);
        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('listByTargetNick')->willReturn([$ignore1, $ignore2]);
        $this->memoIgnoreRepository = $memoIgnore;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturnCallback(static fn (int $id): ?string => 2 === $id ? 'Bob' : null);
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::List));

        self::assertSame(IgnoreMemoOutcome::ListNick, $result->outcome);
        self::assertSame(['Bob', '3'], $result->ignoredNicks);
    }

    #[Test]
    public function listChannelIgnoresSuccessfully(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(10, '#Ares'));
        $this->channelPort = $channelPort;

        $ignore1 = new MemoIgnore(null, 10, 2);
        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('listByTargetChannel')->willReturn([$ignore1]);
        $this->memoIgnoreRepository = $memoIgnore;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturn('Bob');
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new IgnoreMemo(1, IgnoreMemoAction::List, '#ares'));

        self::assertSame(IgnoreMemoOutcome::ListChannel, $result->outcome);
        self::assertSame('#Ares', $result->channelName);
        self::assertSame(['Bob'], $result->ignoredNicks);
    }
}
