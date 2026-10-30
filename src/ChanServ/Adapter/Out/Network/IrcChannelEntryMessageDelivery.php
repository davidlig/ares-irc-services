<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelEntryMessageDelivery;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;

final readonly class IrcChannelEntryMessageDelivery implements ChannelEntryMessageDelivery
{
    public function __construct(
        private SendNoticePort $notices,
        private ServiceUidRegistry $uidRegistry,
        private IrcRenderer $renderer,
    ) {}

    public function deliver(string $channelName, string $targetUid, string $entryMessage): void
    {
        $this->notices->sendNotice(
            $this->uidRegistry->getUid('chanserv') ?? '',
            $targetUid,
            $this->renderer->component(Component::Text, '%channel% %message%', [
                '%channel%' => new Fragment($channelName, Component::ChannelTag),
                '%message%' => $entryMessage,
            ]),
        );
    }
}
