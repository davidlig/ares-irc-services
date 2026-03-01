<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Set;

use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;

final readonly class SetMlockHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.mlock.syntax')]);

            return;
        }
        $on = 'ON' === $normalized;
        if ($on) {
            $this->setMlockFromCurrentChannelState($context, $channel);
        } else {
            $channel->setMlock(false, '', []);
        }
        $this->channelRepository->save($channel);
        if ($on) {
            $this->eventDispatcher->dispatch(new ChannelMlockUpdatedEvent($channel->getName()));
        }
        $modesDisplay = '';
        if ($on) {
            $modesDisplay = $channel->getMlock();
            if ('' === $modesDisplay) {
                $modesDisplay = $context->trans('set.mlock.no_modes');
            }
            $context->reply('set.mlock.on', ['%modes%' => $modesDisplay]);
        } else {
            $context->reply('set.mlock.off');
        }

        $nick = $context->sender?->nick ?? '';
        if ('' !== $nick && $on) {
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->trans('set.mlock.notice_on', [
                '%nick%' => $nick,
                '%modes%' => $modesDisplay,
            ]));
        } elseif ('' !== $nick) {
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->trans('set.mlock.notice_off', ['%nick%' => $nick]));
        }
    }

    /**
     * When turning MLOCK on, lock the current channel state (modes + params) so e.g. +l 100 is preserved.
     */
    private function setMlockFromCurrentChannelState(ChanServContext $context, RegisteredChannel $channel): void
    {
        $view = $context->getChannelLookup()->findByChannelName($channel->getName());
        $support = $context->getChannelModeSupport();
        $unsetWithout = $support->getChannelSettingModesUnsetWithoutParam();
        $unsetWith = $support->getChannelSettingModesUnsetWithParam();
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $allowedLetters = array_flip(array_merge($unsetWithout, $unsetWith, $withParamOnSet));

        if (null === $view || '' === $view->modes) {
            $channel->setMlock(true, '', []);

            return;
        }

        $letters = [];
        $params = [];
        foreach (str_split($view->modes) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            if ('r' === $c) {
                continue;
            }
            if (!isset($allowedLetters[$c])) {
                continue;
            }
            $letters[] = $c;
            if (in_array($c, $withParamOnSet, true)) {
                $param = $view->getModeParam($c);
                if (null !== $param && '' !== $param) {
                    $params[$c] = $param;
                }
            }
        }

        $modeString = [] === $letters ? '' : '+' . implode('', array_unique($letters));
        $channel->setMlock(true, $modeString, $params);
    }
}
