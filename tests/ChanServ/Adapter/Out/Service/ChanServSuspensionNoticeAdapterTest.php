<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\Out\Service\ChanServSuspensionNoticeAdapter;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServSuspensionNoticeAdapter::class)]
final class ChanServSuspensionNoticeAdapterTest extends TestCase
{
    #[Test]
    public function warningUsesInjectedStyleAndDoesNotInterpolateReason(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendNoticeToChannel')->with('#test', '! Suspended: %reason%');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Suspended: %reason%');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['warning' => '!'])));
        new ChanServSuspensionNoticeAdapter($notifier, $translator, $renderer, 'en')->notifyChannelSuspended('#test', '%reason%');
    }

    #[Test]
    public function translatesAndSendsNoticeToChannel(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'suspend.notice_channel',
                [],
                'chanserv',
                'es',
            )
            ->willReturn('Canal suspendido por Spamming channel');

        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'Canal suspendido por Spamming channel');

        $adapter = new ChanServSuspensionNoticeAdapter($notifier, $translator, $this->plainRenderer(), 'es');
        $adapter->notifyChannelSuspended('#test', 'Spamming channel');
    }

    #[Test]
    public function handlesNullReasonGracefully(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'suspend.notice_channel',
                [],
                'chanserv',
                'en',
            )
            ->willReturn('Channel suspended');

        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'Channel suspended');

        $adapter = new ChanServSuspensionNoticeAdapter($notifier, $translator, $this->plainRenderer(), 'en');
        $adapter->notifyChannelSuspended('#test', null);
    }

    private function plainRenderer(): IrcRenderer
    {
        return new IrcRenderer(new FixedStyleProvider(new PresentationStyle()));
    }
}
