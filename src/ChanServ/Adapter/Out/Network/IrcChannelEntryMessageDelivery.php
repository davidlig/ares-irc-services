<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\Application\Port\SendNoticePort;
use App\Application\Shared\ServiceUidRegistry;
use App\ChanServ\Application\Port\Out\ChannelEntryMessageDelivery;

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
