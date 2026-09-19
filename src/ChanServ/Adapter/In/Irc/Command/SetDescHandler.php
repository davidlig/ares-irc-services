<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandlerInterface;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingOutcome;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class SetDescHandler implements SetOptionHandlerInterface
{
    use BuildsUpdateChannelSettingRequest;

    public function __construct(private UpdateChannelSettingHandlerInterface $handler) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $request = $this->settingRequest($context, $channel, ChannelSetting::Description, $value);
        if (null === $request) {
            return;
        }
        $result = $this->handler->handle($request);
        if (UpdateChannelSettingOutcome::MissingValue === $result->outcome) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.desc.syntax')]);

            return;
        }
        $context->reply('set.desc.updated');
    }
}
