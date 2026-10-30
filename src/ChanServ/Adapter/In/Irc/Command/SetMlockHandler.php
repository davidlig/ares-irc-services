<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\MlockStateFromChannelResolver;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlock;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockHandlerInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function array_map;
use function implode;
use function strtoupper;
use function trim;

final readonly class SetMlockHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private ConfigureChannelMlockHandlerInterface $configureMlock,
        private MlockStateFromChannelResolver $mlockStateResolver,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        if (null === $context->sender) {
            return;
        }
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('presentation.error.syntax', ['syntax' => new Fragment($context->trans('set.mlock.syntax'), Component::Command)], Role::Error);

            return;
        }
        $on = 'ON' === $normalized;
        $modeLock = $on ? $this->modeLockFromCurrentChannelState($context, $channel) : ChannelModeLock::inactive();
        $result = $this->configureMlock->handle(new ConfigureChannelMlock(
            $channel,
            $modeLock,
            $context->sender->nick,
            new DateTimeImmutable(),
        ));
        $modesDisplay = '';
        if ($on) {
            $letters = array_map(
                static fn ($setting): string => $setting->mode->value,
                $result->modeLock->settings,
            );
            $modesDisplay = [] === $letters ? '' : '+' . implode('', $letters);
            if ('' === $modesDisplay) {
                $modesDisplay = $context->trans('set.mlock.no_modes');
            }
            $context->reply('set.mlock.on', ['%modes%' => $modesDisplay], Role::Success);
        } else {
            $context->reply('set.mlock.off', role: Role::Success);
        }

        $nick = $context->sender->nick;
        if ('' !== $nick && $on) {
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->renderReply(Role::Success, 'set.mlock.notice_on', [
                '%nickname%' => new Fragment($nick, Component::Actor),
                '%modes%' => $modesDisplay,
            ]));
        } elseif ('' !== $nick) {
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->renderReply(Role::Success, 'set.mlock.notice_off', ['%nickname%' => new Fragment($nick, Component::Actor)]));
        }
    }

    /**
     * When turning MLOCK on, lock the current channel state (modes + params) so e.g. +l 100 is preserved.
     * If the channel is not on the network or has no modes, MLOCK is stored as active with no modes:
     * on burst or first join the subscriber will strip all channel modes (except +r set by services).
     */
    private function modeLockFromCurrentChannelState(ChanServContext $context, RegisteredChannel $channel): ChannelModeLock
    {
        $view = $context->getChannelLookup()->findByChannelName($channel->getName());
        if (null === $view || '' === $view->modes) {
            return ChannelModeLock::active();
        }

        return $this->mlockStateResolver->resolve($view, $context->getChannelModeSupport());
    }
}
