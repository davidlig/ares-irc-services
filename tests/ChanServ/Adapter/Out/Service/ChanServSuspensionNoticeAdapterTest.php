<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\Out\Service\ChanServSuspensionNoticeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServSuspensionNoticeAdapter::class)]
final class ChanServSuspensionNoticeAdapterTest extends TestCase
{
    #[Test]
    public function translatesAndSendsNoticeToChannel(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'suspend.notice_channel',
                ['%reason%' => 'Spamming channel'],
                'chanserv',
                'es',
            )
            ->willReturn('Canal suspendido por Spamming channel');

        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'Canal suspendido por Spamming channel');

        $adapter = new ChanServSuspensionNoticeAdapter($notifier, $translator, 'es');
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
                ['%reason%' => ''],
                'chanserv',
                'en',
            )
            ->willReturn('Channel suspended');

        $notifier->expects(self::once())
            ->method('sendNoticeToChannel')
            ->with('#test', 'Channel suspended');

        $adapter = new ChanServSuspensionNoticeAdapter($notifier, $translator, 'en');
        $adapter->notifyChannelSuspended('#test', null);
    }
}
