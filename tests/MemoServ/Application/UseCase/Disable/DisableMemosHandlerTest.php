<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\Disable;

use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\UseCase\Disable\DisableMemos;
use App\MemoServ\Application\UseCase\Disable\DisableMemosHandler;
use App\MemoServ\Application\UseCase\Disable\DisableMemosOutcome;
use App\MemoServ\Application\UseCase\Disable\DisableMemosResult;
use App\MemoServ\Domain\Entity\MemoSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DisableMemosHandler::class)]
#[CoversClass(DisableMemos::class)]
#[CoversClass(DisableMemosResult::class)]
final class DisableMemosHandlerTest extends TestCase
{
    private MemoChannelPort $channelPort;

    private MemoSettingsRepositoryInterface $memoSettingsRepository;

    protected function setUp(): void
    {
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoSettingsRepository = $this->createStub(MemoSettingsRepositoryInterface::class);
    }

    private function createHandler(): DisableMemosHandler
    {
        return new DisableMemosHandler($this->channelPort, $this->memoSettingsRepository);
    }

    #[Test]
    public function disablesNickMemosWhenNoSettingsRow(): void
    {
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::callback(static fn (MemoSettings $s): bool => 1 === $s->getTargetNickId() && !$s->isEnabled()));
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1));

        self::assertSame(DisableMemosOutcome::DisabledNick, $result->outcome);
    }

    #[Test]
    public function disablesNickMemosWhenPreviouslyEnabled(): void
    {
        $settings = new MemoSettings(1, null, true);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);
        $settingsRepo->expects(self::once())->method('save')->with($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1));

        self::assertSame(DisableMemosOutcome::DisabledNick, $result->outcome);
        self::assertFalse($settings->isEnabled());
    }

    #[Test]
    public function returnsAlreadyDisabledNickWhenAlreadyDisabled(): void
    {
        $settings = new MemoSettings(1, null, false);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetNick')->willReturn($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1));

        self::assertSame(DisableMemosOutcome::AlreadyDisabledNick, $result->outcome);
    }

    #[Test]
    public function returnsChannelNotRegisteredWhenChannelNotFound(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(null);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1, '#unknown'));

        self::assertSame(DisableMemosOutcome::ChannelNotRegistered, $result->outcome);
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
        $result = $handler->handle(new DisableMemos(1, '#ares'));

        self::assertSame(DisableMemosOutcome::FounderOnly, $result->outcome);
        self::assertSame('#ares', $result->channelName);
    }

    #[Test]
    public function disablesChannelMemosWhenFounderAndNoSettingsRow(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(true);
        $this->channelPort = $channelPort;

        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn(null);
        $settingsRepo->expects(self::once())->method('save')->with(self::callback(static fn (MemoSettings $s): bool => 5 === $s->getTargetChannelId() && !$s->isEnabled()));
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1, '#ares'));

        self::assertSame(DisableMemosOutcome::DisabledChannel, $result->outcome);
        self::assertSame('#Ares', $result->channelName);
    }

    #[Test]
    public function returnsAlreadyDisabledChannelWhenAlreadyDisabled(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(true);
        $this->channelPort = $channelPort;

        $settings = new MemoSettings(null, 5, false);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1, '#ares'));

        self::assertSame(DisableMemosOutcome::AlreadyDisabledChannel, $result->outcome);
        self::assertSame('#ares', $result->channelName);
    }

    #[Test]
    public function disablesChannelMemosWhenPreviouslyEnabled(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('isChannelFounder')->willReturn(true);
        $this->channelPort = $channelPort;

        $settings = new MemoSettings(null, 5, true);
        $settingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('findByTargetChannel')->willReturn($settings);
        $settingsRepo->expects(self::once())->method('save')->with($settings);
        $this->memoSettingsRepository = $settingsRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DisableMemos(1, '#ares'));

        self::assertSame(DisableMemosOutcome::DisabledChannel, $result->outcome);
        self::assertFalse($settings->isEnabled());
    }
}
