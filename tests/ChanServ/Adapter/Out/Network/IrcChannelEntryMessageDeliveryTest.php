<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChannelEntryMessageDelivery;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelEntryMessageDelivery::class)]
final class IrcChannelEntryMessageDeliveryTest extends TestCase
{
    public function testRendersChannelWithSelectedStyleAndPreservesUserText(): void
    {
        $notices = $this->createMock(SendNoticePort::class);
        $notices->expects(self::once())->method('sendNotice')->with('service', 'user', "<\x0305#test\x03\x0F> %channel% \x02hello");
        $registry = $this->createStub(ServiceUidRegistry::class);
        $registry->method('getUid')->willReturn('service');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle([Component::ChannelTag->value => new Appearance('05', prefix: '<', suffix: '>')])));
        new IrcChannelEntryMessageDelivery($notices, $registry, $renderer)->deliver('#test', 'user', "%channel% \x02hello");
    }
}
