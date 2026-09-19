<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSetting;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;

use function base64_decode;
use function inet_ntop;
use function sprintf;

trait BuildsUpdateChannelSettingRequest
{
    private function settingRequest(
        ChanServContext $context,
        RegisteredChannel $channel,
        ChannelSetting $setting,
        string $value,
    ): ?UpdateChannelSetting {
        $sender = $context->sender;
        if (null === $sender) {
            return null;
        }

        return new UpdateChannelSetting(
            channel: $channel,
            setting: $setting,
            value: $value,
            actorNickname: $sender->nick,
            actorAccountId: $context->senderAccount?->id,
            actorIp: $this->decodeSettingIp($sender->ipBase64),
            actorHost: sprintf('%s@%s', $sender->ident, $sender->hostname),
            occurredAt: new DateTimeImmutable(),
        );
    }

    private function decodeSettingIp(string $ipBase64): string
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
