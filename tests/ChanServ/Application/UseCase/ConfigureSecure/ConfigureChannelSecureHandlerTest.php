<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ConfigureSecure;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSecureUpdatedEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecure;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecureHandler;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ConfigureChannelSecure::class)]
#[CoversClass(ConfigureChannelSecureHandler::class)]
final class ConfigureChannelSecureHandlerTest extends TestCase
{
    #[Test]
    public function itPersistsAndPublishesWhenSecureIsEnabled(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('save')->with($channel);
        $events = $this->createMock(ChanServEventPublisher::class);
        $published = [];
        $events->expects(self::exactly(2))->method('publish')->willReturnCallback(static function (object $event) use (&$published): void {
            $published[] = $event;
        });
        $saved = null;
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });

        new ConfigureChannelSecureHandler($channels, $events, new ChannelHistoryService($history))->handle(new ConfigureChannelSecure($channel, true, 'Founder', new DateTimeImmutable()));

        self::assertTrue($channel->isSecure());
        self::assertInstanceOf(ChannelSecureUpdatedEvent::class, $published[0]);
        self::assertSame('#test', $published[0]->channelName);
        self::assertInstanceOf(ChannelSecureEnabledEvent::class, $published[1]);
        self::assertSame('#test', $published[1]->channelName);
        self::assertNotNull($saved);
        self::assertSame('SET_SECURE_ON', $saved->getAction());
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame([], $saved->getExtraData());
    }

    #[Test]
    public function itPersistsWithoutPublishingWhenSecureIsDisabled(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
        $channel->configureSecure(true);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('save')->with($channel);
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof ChannelSecureUpdatedEvent && '#test' === $event->channelName,
        ));

        new ConfigureChannelSecureHandler($channels, $events, $this->historyService())->handle(new ConfigureChannelSecure($channel, false, 'Founder', new DateTimeImmutable()));

        self::assertFalse($channel->isSecure());
    }

    private function historyService(): ChannelHistoryService
    {
        return new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class));
    }
}
