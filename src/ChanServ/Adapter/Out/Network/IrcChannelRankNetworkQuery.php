<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelModeSupportInterface;
use App\ChanServ\Application\Model\ChannelMember;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Application\Port\In\NickAccountQuery;

use function in_array;

final readonly class IrcChannelRankNetworkQuery implements ChannelRankNetworkQuery
{
    public function __construct(
        private ChannelLookupPort $channels,
        private NetworkUserLookupPort $users,
        private NickAccountQuery $registeredNicks,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelRankModeMapper $rankMapper,
        private string $chanservNick,
    ) {}

    public function findChannel(string $channelName): ?ChannelRankNetworkState
    {
        $view = $this->channels->findByChannelName($channelName);
        if (null === $view) {
            return null;
        }

        $support = $this->modeSupportProvider->getSupport();

        return new ChannelRankNetworkState(
            name: $view->name,
            members: $this->members($view),
            supportedRanks: $this->supportedRanks($support),
        );
    }

    /** @return list<ChannelMember> */
    private function members(ChannelView $view): array
    {
        $members = [];
        foreach ($view->members as $entry) {
            $uid = $entry['uid'];
            if ('' === $uid) {
                continue;
            }
            $user = $this->users->findByUid($uid) ?? $this->users->findByNick($uid);
            if (null === $user) {
                continue;
            }
            $account = $this->registeredNicks->findAccountByNick($user->nick);
            $accountId = null !== $account && $account->registered ? $account->id : null;
            $letters = $entry['prefixLetters'] ?? [$entry['roleLetter']];
            $ranks = [];
            foreach ($letters as $letter) {
                $rank = $this->rankMapper->fromLetter($letter);
                if (null !== $rank && !in_array($rank, $ranks, true)) {
                    $ranks[] = $rank;
                }
            }

            $members[] = new ChannelMember(
                uid: $user->uid,
                nickname: $user->nick,
                identified: $user->isIdentified,
                operator: $user->isOper,
                service: 0 === strcasecmp($user->nick, $this->chanservNick),
                registeredNickId: $accountId,
                currentRanks: $ranks,
            );
        }

        return $members;
    }

    /** @return list<ChannelRank> */
    private function supportedRanks(ChannelModeSupportInterface $support): array
    {
        $ranks = [];
        foreach ($support->getSupportedPrefixModes() as $letter) {
            $rank = $this->rankMapper->fromLetter($letter);
            if (null !== $rank) {
                $ranks[] = $rank;
            }
        }

        return $ranks;
    }
}
