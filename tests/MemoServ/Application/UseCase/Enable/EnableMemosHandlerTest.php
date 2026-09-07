<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\Enable;

use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\UseCase\Enable\EnableMemos;
use App\MemoServ\Application\UseCase\Enable\EnableMemosHandler;
use App\MemoServ\Application\UseCase\Enable\EnableMemosOutcome;
use App\MemoServ\Application\UseCase\Enable\EnableMemosResult;
use App\MemoServ\Domain\Entity\MemoSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnableMemosHandler::class)]
#[CoversClass(EnableMemos::class)]
#[CoversClass(EnableMemosResult::class)]
final class EnableMemosHandlerTest extends TestCase
{
    private MemoChannelPort $channelPort;

    private MemoSettingsRepositoryInterface $memoSettingsRepository;

    protected function setUp(): void
    {
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoSettingsRepository = $this->createStub(MemoSettingsRepositoryInterface::class);
    }

    private function createHandler(): EnableMemosHandler
    {
        return new EnableMemosHandler($this->channelPort, $this->memoSettingsRepository);
    }

    #[Test]
    public function enablesNickMemosWhenNoSettingsRow(): void
    {
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::callback(static fn (MemoSettings $s): bool => 1 === $s->getTargetNickId() && $s->isEnabled()));
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1));

        self::assertSame(EnableMemosOutcome::EnabledNick, $result->outcome);
    }

    #[Test]
    public function enablesNickMemosWhenPreviouslyDisabled(): void
    {
        $settings = new MemoSettings(1, null, false);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);
        $settingsRepo->expects(self::once())->method('save')->with($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1));

        self::assertSame(EnableMemosOutcome::EnabledNick, $result->outcome);
        self::assertTrue($settings->isEnabled());
    }

    #[Test]
    public function returnsAlreadyEnabledNickWhenAlreadyEnabled(): void
    {
        $settings = new MemoSettings(1, null, true);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1));

        self::assertSame(EnableMemosOutcome::AlreadyEnabledNick, $result->outcome);
    }

    #[Test]
    public function returnsChannelNotRegisteredWhenChannelNotFound(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(null);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1, '#unknown'));

        self::assertSame(EnableMemosOutcome::ChannelNotRegistered, $result->outcome);
        self::assertSame('#unknown', $result->channelName);
    }

    #[Test]
    public function returnsFounderOnlyWhenUserNotChannelFounder(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(false);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1, '#ares'));

        self::assertSame(EnableMemosOutcome::FounderOnly, $result->outcome);
        self::assertSame('#ares', $result->channelName);
    }

    #[Test]
    public function enablesChannelMemosWhenFounderAndNoSettingsRow(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(true);
        $this->channelPort = $channelPort;

        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::callback(static fn (MemoSettings $s): bool => 5 === $s->getTargetChannelId() && $s->isEnabled()));
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1, '#ares'));

        self::assertSame(EnableMemosOutcome::EnabledChannel, $result->outcome);
        self::assertSame('#Ares', $result->channelName);
    }

    #[Test]
    public function returnsAlreadyEnabledChannelWhenAlreadyEnabled(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(true);
        $this->channelPort = $channelPort;

        $settings = new MemoSettings(null, 5, true);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1, '#ares'));

        self::assertSame(EnableMemosOutcome::AlreadyEnabledChannel, $result->outcome);
        self::assertSame('#ares', $result->channelName);
    }

    #[Test]
    public function enablesChannelMemosWhenPreviouslyDisabled(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(true);
        $this->channelPort = $channelPort;

        $settings = new MemoSettings(null, 5, false);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);
        $settingsRepo->expects(self::once())->method('save')->with($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new EnableMemos(1, '#ares'));

        self::assertSame(EnableMemosOutcome::EnabledChannel, $result->outcome);
        self::assertTrue($settings->isEnabled());
    }
}
