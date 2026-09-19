<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;

final readonly class IrcChannelEntryNetworkQuery implements ChannelEntryNetworkQuery
{
    public function __construct(
        private ChannelLookupPort $channels,
        private NetworkUserLookupPort $users,
        private string $chanservNick,
        private ?BurstCompletePort $burstComplete = null,
    ) {}

    public function synchronizationComplete(): bool
    {
        return $this->burstComplete?->isComplete() ?? false;
    }

    public function findMember(string $uid): ?ChannelEntryMember
    {
        $user = $this->users->findByUid($uid);

        return null === $user ? null : $this->toMember($user);
    }

    public function findChannel(string $channelName): ?ChannelEntryNetworkState
    {
        $channel = $this->channels->findByChannelName($channelName);
        if (null === $channel) {
            return null;
        }

        return new ChannelEntryNetworkState($channel->name, $this->members($channel));
    }

    /** @return list<ChannelEntryMember> */
    private function members(ChannelView $channel): array
    {
        $members = [];
        foreach ($channel->members as $entry) {
            $uid = $entry['uid'];
            if ('' === $uid) {
                continue;
            }

            $member = $this->findMember($uid);
            if (null !== $member) {
                $members[] = $member;
            }
        }

        return $members;
    }

    private function toMember(SenderView $user): ChannelEntryMember
    {
        return new ChannelEntryMember(
            uid: $user->uid,
            nickname: $user->nick,
            userMask: $user->toUserMask(),
            identified: $user->isIdentified,
            operator: $user->isOper,
            service: 0 === strcasecmp($user->nick, $this->chanservNick),
        );
    }
}
