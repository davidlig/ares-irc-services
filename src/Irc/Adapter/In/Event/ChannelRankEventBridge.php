<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Irc\Adapter\Network\Event\ChannelModeReceivedEvent;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Domain\Network\ChannelMemberRole;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

/** Translates concrete IRC MODE grammar to stable semantic rank events. */
final readonly class ChannelRankEventBridge implements EventSubscriberInterface
{
    public function __construct(
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private NetworkUserLookupPort $users,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ChannelModeReceivedEvent::class => ['publishGrantedRanks', 255]];
    }

    public function publishGrantedRanks(ChannelModeReceivedEvent $event): void
    {
        $support = $this->modeSupportProvider->getSupport();
        $params = $event->modeParams;
        $parameterIndex = 0;
        $adding = true;

        foreach (str_split($event->modeStr) as $letter) {
            if ('+' === $letter || '-' === $letter) {
                $adding = '+' === $letter;
                continue;
            }

            $consumesParameter = in_array($letter, $support->getSupportedPrefixModes(), true)
                || in_array($letter, $support->getListModeLetters(), true)
                || ($adding && in_array($letter, $support->getChannelSettingModesWithParamOnSet(), true))
                || (!$adding && in_array($letter, $support->getChannelSettingModesUnsetWithParam(), true));
            if (!$consumesParameter) {
                continue;
            }

            $parameter = $params[$parameterIndex] ?? null;
            ++$parameterIndex;
            $role = ChannelMemberRole::fromModeLetter($letter);
            if (!$adding || null === $role || null === $parameter) {
                continue;
            }

            $user = $this->users->findByUid($parameter) ?? $this->users->findByNick($parameter);
            if (null === $user) {
                continue;
            }

            $this->eventDispatcher->dispatch(new ChannelMemberRankGrantedEvent(
                $event->channelName->value,
                $user->uid,
                $role->value,
            ));
        }
    }
}
