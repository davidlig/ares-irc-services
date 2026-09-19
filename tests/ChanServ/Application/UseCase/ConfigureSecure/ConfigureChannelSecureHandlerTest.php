<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ConfigureSecure;

use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecure;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecureHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigureChannelSecure::class)]
#[CoversClass(ConfigureChannelSecureHandler::class)]
final class ConfigureChannelSecureHandlerTest extends TestCase
{
    #[Test]
    public function itPersistsAndPublishesWhenSecureIsEnabled(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('save')->with($channel);
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof ChannelSecureEnabledEvent && '#test' === $event->channelName,
        ));

        new ConfigureChannelSecureHandler($channels, $events)->handle(new ConfigureChannelSecure($channel, true));

        self::assertTrue($channel->isSecure());
    }

    #[Test]
    public function itPersistsWithoutPublishingWhenSecureIsDisabled(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        $channel->configureSecure(true);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('save')->with($channel);
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');

        new ConfigureChannelSecureHandler($channels, $events)->handle(new ConfigureChannelSecure($channel, false));

        self::assertFalse($channel->isSecure());
    }
}
