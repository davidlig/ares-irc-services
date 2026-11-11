<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceNojoin;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Domain\Entity\RegisteredChannel;

interface EnforceChannelNojoinHandlerInterface
{
    public function handle(EnforceChannelNojoin $command): int;

    /** @param list<ChannelEntryMember> $members */
    public function handleKnownChannel(RegisteredChannel $channel, array $members): int;

    /**
     * @param list<ChannelEntryMember>       $members
     * @param array<string, ChanAccountView> $accounts
     * @param array<int, int>                $access
     */
    public function handleKnownChannelWithSnapshot(RegisteredChannel $channel, array $members, int $requiredAccess, array $accounts, array $access): int;
}
