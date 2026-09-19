<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelEntryMessageDelivery;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidRegistry;

use function sprintf;

final readonly class IrcChannelEntryMessageDelivery implements ChannelEntryMessageDelivery
{
    public function __construct(
        private SendNoticePort $notices,
        private ServiceUidRegistry $uidRegistry,
    ) {}

    public function deliver(string $channelName, string $targetUid, string $entryMessage): void
    {
        $this->notices->sendNotice(
            $this->uidRegistry->getUid('chanserv') ?? '',
            $targetUid,
            sprintf("[\x0303%s\x03] %s", $channelName, $entryMessage),
        );
    }
}
