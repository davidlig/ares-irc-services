<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleAction;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycle;
use DateTimeImmutable;
use LogicException;

use function base64_decode;
use function inet_ntop;
use function sprintf;

trait BuildsChannelLifecycleRequest
{
    private function lifecycleRequest(
        ChanServContext $context,
        string $channelName,
        ChannelLifecycleAction $action,
        ?string $reason = null,
        ?string $duration = null,
        bool $force = false,
    ): ManageChannelLifecycle {
        $sender = $context->sender;
        if (null === $sender) {
            throw new LogicException('A sender is required for channel lifecycle commands.');
        }

        return new ManageChannelLifecycle(
            channelName: $channelName,
            action: $action,
            actorNickname: $sender->nick,
            occurredAt: new DateTimeImmutable(),
            actorAccountId: $context->senderAccount?->id,
            actorIp: $this->decodeLifecycleIp($sender->ipBase64),
            actorHost: sprintf('%s@%s', $sender->ident, $sender->hostname),
            reason: $reason,
            duration: $duration,
            force: $force,
        );
    }

    private function decodeLifecycleIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);
        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
