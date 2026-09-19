<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServChannelForbiddenSubscriber;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelForbiddenSubscriber::class)]
final class ChanServChannelForbiddenSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvent(): void
    {
        self::assertSame(
            [ChannelForbiddenEvent::class => ['onChannelForbidden', 0]],
            ChanServChannelForbiddenSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function delegatesChannelNameToApplication(): void
    {
        $enforcement = $this->createMock(ForbiddenChannelEnforcement::class);
        $enforcement->expects(self::once())->method('enforcePublishedForbiddenChannel')->with('#forbidden');

        new ChanServChannelForbiddenSubscriber($enforcement)->onChannelForbidden(new ChannelForbiddenEvent(
            channelId: 1,
            channelName: '#forbidden',
            channelNameLower: '#forbidden',
            reason: 'spam',
            performedBy: 'Oper',
            occurredAt: new DateTimeImmutable('2026-01-02 03:04:05'),
        ));
    }
}
