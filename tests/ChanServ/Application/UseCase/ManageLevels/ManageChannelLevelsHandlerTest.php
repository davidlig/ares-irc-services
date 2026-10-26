<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageLevels;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevels;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsAction;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsHandler;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsOutcome;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ManageChannelLevelsHandler::class)]
final class ManageChannelLevelsHandlerTest extends TestCase
{
    public function testIncompleteActionInputHasNoSideEffects(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::never())->method('save');
        $levels->expects(self::never())->method('removeAllForChannel');
        $handler = new ManageChannelLevelsHandler($channels, $levels, $this->historyService());

        foreach ([[null, 10], ['', 10], ['ACCESSLIST', null]] as [$key, $value]) {
            $result = $handler->handle(new ManageChannelLevels(
                '#test',
                1,
                true,
                ManageChannelLevelsAction::Set,
                ['ACCESSLIST'],
                'Founder',
                new DateTimeImmutable('2026-01-01'),
                $key,
                $value,
            ));
            self::assertSame(ManageChannelLevelsOutcome::InvalidRequest, $result->outcome);
        }
    }

    public function testSetRecordsPriorEffectiveDefaultAndRequestedLevel(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable('2026-01-01'), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 10);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('findByChannelAndKey')->with($channel->getId(), 'AUTOOP')->willReturn(null);
        $levels->expects(self::once())->method('save');
        $saved = null;
        $historyRepository = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepository->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });
        $when = new DateTimeImmutable('2026-01-02');

        $result = new ManageChannelLevelsHandler($channels, $levels, new ChannelHistoryService($historyRepository))->handle(new ManageChannelLevels(
            '#test',
            1,
            true,
            ManageChannelLevelsAction::Set,
            ['AUTOOP'],
            'Founder',
            $when,
            'AUTOOP',
            9,
        ));

        self::assertSame(ManageChannelLevelsOutcome::LevelSet, $result->outcome);
        self::assertNotNull($saved);
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame('LEVELS_SET', $saved->getAction());
        self::assertSame(['option' => 'AUTOOP', 'old_value' => 300, 'new_value' => 9], $saved->getExtraData());
        self::assertSame($when, $saved->getPerformedAt());
    }

    public function testResetRecordsOnlyActorMetadata(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('removeAllForChannel')->with(1);
        $saved = null;
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });

        $result = new ManageChannelLevelsHandler($channels, $levels, new ChannelHistoryService($history))->handle(new ManageChannelLevels(
            '#test',
            1,
            true,
            ManageChannelLevelsAction::Reset,
            ['AUTOOP'],
            'Founder',
            new DateTimeImmutable('2026-01-02'),
        ));

        self::assertSame(ManageChannelLevelsOutcome::LevelsReset, $result->outcome);
        self::assertNotNull($saved);
        self::assertSame('LEVELS_RESET', $saved->getAction());
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame([], $saved->getExtraData());
    }

    public function testSetRecordsExistingOverrideRatherThanDefault(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $existing = new ChannelLevel(1, 'AUTOOP', 250);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('findByChannelAndKey')->with(1, 'AUTOOP')->willReturn($existing);
        $levels->expects(self::once())->method('save')->with($existing);
        $saved = null;
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });

        new ManageChannelLevelsHandler($channels, $levels, new ChannelHistoryService($history))->handle(new ManageChannelLevels(
            '#test',
            1,
            true,
            ManageChannelLevelsAction::Set,
            ['AUTOOP'],
            'Founder',
            new DateTimeImmutable('2026-01-02'),
            'AUTOOP',
            350,
        ));

        self::assertNotNull($saved);
        self::assertSame(['option' => 'AUTOOP', 'old_value' => 250, 'new_value' => 350], $saved->getExtraData());
    }

    private function historyService(): ChannelHistoryService
    {
        return new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class));
    }
}
