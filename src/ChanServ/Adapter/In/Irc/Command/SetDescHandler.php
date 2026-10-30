<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandlerInterface;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingOutcome;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

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
            $context->reply('presentation.error.syntax', ['syntax' => new Fragment($context->trans('set.desc.syntax'), Component::Command)], Role::Error);

            return;
        }
        $context->reply('set.desc.updated', role: Role::Success);
    }
}
