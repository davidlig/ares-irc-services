<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServUnsuspendSubscriber;
use App\ChanServ\Application\Port\In\UnsuspendedChannelRestoration;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Shared\Application\Port\ServiceDebugNotifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServUnsuspendSubscriber::class)]
final class ChanServUnsuspendSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToChannelUnsuspendedEvent(): void
    {
        self::assertSame(
            [ChannelUnsuspendedEvent::class => ['onChannelUnsuspended', 0]],
            ChanServUnsuspendSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function restoresBeforePublishingTranslatedDebugNotification(): void
    {
        $sequence = [];
        $restoration = $this->createMock(UnsuspendedChannelRestoration::class);
        $restoration->expects(self::once())->method('restore')->with('#test')
            ->willReturnCallback(static function () use (&$sequence): string {
                $sequence[] = 'restore';

                return '#Test';
            });
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('unsuspend.reason_expired', [], 'chanserv', 'es')
            ->willReturn('suspensión expirada');
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            'Admin',
            'UNSUSPEND',
            '#Test',
            null,
            null,
            'suspensión expirada',
        )->willReturnCallback(static function () use (&$sequence): void {
            $sequence[] = 'debug';
        });

        new ChanServUnsuspendSubscriber($restoration, $debugNotifier, $translator, 'es')
            ->onChannelUnsuspended($this->event('Admin'));

        self::assertSame(['restore', 'debug'], $sequence);
    }

    #[Test]
    public function doesNotPresentWhenRegisteredChannelNoLongerExists(): void
    {
        $restoration = $this->createStub(UnsuspendedChannelRestoration::class);
        $restoration->method('restore')->willReturn(null);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');

        new ChanServUnsuspendSubscriber($restoration, $debugNotifier, $translator)
            ->onChannelUnsuspended($this->event('*'));
    }

    private function event(string $performedBy): ChannelUnsuspendedEvent
    {
        return new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            performedBy: $performedBy,
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: '*',
        );
    }
}
