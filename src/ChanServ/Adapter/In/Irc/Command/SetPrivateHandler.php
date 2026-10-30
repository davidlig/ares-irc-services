<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandlerInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function strtoupper;
use function trim;

final readonly class SetPrivateHandler implements SetOptionHandlerInterface
{
    use BuildsUpdateChannelSettingRequest;

    public function __construct(private UpdateChannelSettingHandlerInterface $handler) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('presentation.error.syntax', ['syntax' => new Fragment($context->trans('set.private.syntax'), Component::Command)], Role::Error);

            return;
        }
        $request = $this->settingRequest($context, $channel, ChannelSetting::Private, $normalized);
        if (null === $request) {
            return;
        }
        $this->handler->handle($request);
        $context->reply('ON' === $normalized ? 'set.private.on' : 'set.private.off', role: Role::Success);
    }
}
