<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecure;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecureHandlerInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function strtoupper;
use function trim;

final readonly class SetSecureHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private ConfigureChannelSecureHandlerInterface $configureSecure,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        if (null === $context->sender) {
            return;
        }
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('presentation.error.syntax', ['syntax' => new Fragment($context->trans('set.secure.syntax'), Component::Command)], Role::Error);

            return;
        }
        $on = 'ON' === $normalized;
        $this->configureSecure->handle(new ConfigureChannelSecure(
            $channel,
            $on,
            $context->sender->nick,
            new DateTimeImmutable(),
        ));
        $context->reply($on ? 'set.secure.on' : 'set.secure.off', role: Role::Success);

        $nick = $context->sender->nick;
        if ('' !== $nick) {
            $key = $on ? 'set.secure.notice_on' : 'set.secure.notice_off';
            $notice = $context->renderReply(Role::Success, $key, ['%nickname%' => new Fragment($nick, Component::Actor)]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }
}
