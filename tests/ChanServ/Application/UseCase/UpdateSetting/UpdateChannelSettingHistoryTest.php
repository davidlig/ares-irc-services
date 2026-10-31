<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\UpdateSetting;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\EmailDomainPolicy;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandler;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(UpdateChannelSettingHandler::class)]
final class UpdateChannelSettingHistoryTest extends TestCase
{
    /** @return iterable<string, array{ChannelSetting, string, string}> */
    public static function recordedSettings(): iterable
    {
        yield 'description' => [ChannelSetting::Description, 'New description', 'SET_DESC'];
        yield 'url' => [ChannelSetting::Url, 'https://example.test', 'SET_URL'];
        yield 'email' => [ChannelSetting::Email, 'founder@example.test', 'SET_EMAIL'];
        yield 'entry message' => [ChannelSetting::EntryMessage, 'Welcome here', 'SET_ENTRYMSG'];
        yield 'topic lock on' => [ChannelSetting::TopicLock, 'ON', 'SET_TOPICLOCK_ON'];
        yield 'private off' => [ChannelSetting::Private, 'OFF', 'SET_PRIVATE_OFF'];
        yield 'verbose on' => [ChannelSetting::Verbose, 'ON', 'SET_VERBOSE_ON'];
        yield 'verbose off' => [ChannelSetting::Verbose, 'OFF', 'SET_VERBOSE_OFF'];
    }

    #[Test]
    #[DataProvider('recordedSettings')]
    public function successfulSettingRecordsOnlyActorAndApprovedAction(ChannelSetting $setting, string $value, string $action): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable('2026-01-01'), '#test', 1, 'Initial');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 42);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $saved = null;
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });
        $now = new DateTimeImmutable('2026-01-02');

        $handler = new UpdateChannelSettingHandler(
            $this->createStub(EmailDomainPolicy::class),
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($history),
        );
        $result = $handler->handle(new UpdateChannelSetting($channel, $setting, $value, 'Founder', 7, '192.0.2.1', 'ident@host', $now));

        self::assertContains($result->outcome->name, ['Updated', 'Cleared']);
        self::assertNotNull($saved);
        self::assertSame($action, $saved->getAction());
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame([], $saved->getExtraData());
        self::assertSame($now, $saved->getPerformedAt());
    }

    #[Test]
    public function invalidSettingDoesNotRecordHistory(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable('2026-01-01'), '#test', 1, 'Initial');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 42);
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::never())->method('save');
        $handler = new UpdateChannelSettingHandler(
            $this->createStub(EmailDomainPolicy::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($history),
        );

        $result = $handler->handle(new UpdateChannelSetting(
            $channel,
            ChannelSetting::Email,
            'not-an-email',
            'Founder',
            7,
            '192.0.2.1',
            'ident@host',
            new DateTimeImmutable('2026-01-02'),
        ));

        self::assertSame('InvalidEmail', $result->outcome->name);
    }
}
