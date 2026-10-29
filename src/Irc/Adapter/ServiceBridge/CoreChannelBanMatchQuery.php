<?php

declare(strict_types=1);

namespace App\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\Protocol\ChannelBanIdentity;
use App\Irc\Adapter\Protocol\ChannelBanMatcherProvider;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelBanMatchQuery;
use App\Irc\Application\Port\In\ChannelBanMatchResult;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use InvalidArgumentException;

use function base64_decode;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function strlen;

final readonly class CoreChannelBanMatchQuery implements ChannelBanMatchQuery
{
    public function __construct(
        private ChannelRepositoryInterface $channels,
        private NetworkUserRepositoryInterface $users,
        private ActiveProtocolModuleHolderInterface $modules,
    ) {}

    public function findMatchingBans(string $channelName, string $uid): ?ChannelBanMatchResult
    {
        try {
            $channel = $this->channels->findByName(new ChannelName($channelName));
            $userId = new Uid($uid);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (null === $channel) {
            return new ChannelBanMatchResult(false);
        }
        $module = $this->modules->getProtocolModule();
        if (!$module instanceof ChannelBanMatcherProvider) {
            return null;
        }
        $matcher = $module->getChannelBanMatcher();
        $user = $this->users->findByUid($userId);
        if (null === $matcher || null === $user) {
            return null;
        }
        // Decode locally: unknown addresses must never become wildcard subjects.
        $ip = $user->ipBase64;
        if (false === inet_pton($ip)) {
            $binary = base64_decode($ip, true);
            if (false === $binary || !in_array(strlen($binary), [4, 16], true)) {
                return null;
            }
            $ip = (string) inet_ntop($binary);
        }
        $identity = new ChannelBanIdentity($user->getNick()->value, $user->ident->value, $user->realIdent->value, $user->hostname, $user->getDisplayHost(), $user->cloakedHost, $ip);
        $masks = [];
        foreach ($channel->getBans() as $mask) {
            if ($matcher->matches($mask, $identity)) {
                $masks[] = $mask;
            }
        }

        return new ChannelBanMatchResult(true, $masks, $channel->getCreatedAt()->getTimestamp());
    }
}
