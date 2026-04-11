<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\BurstCompletePort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

/**
 * Enforces MLOCK on registered channels: locked modes are fixed; any other
 * channel mode is stripped so the channel has exactly the MLOCK modes (plus +r
 * if set by us). E.g. MLOCK +nt → channel stays +nt (or +ntr); +m, +R, +S etc. are removed.
 * MLOCK with no modes (empty string): strip all channel modes on burst/first join except +r (set by services).
 *
 * Burst: we enforce only on NetworkSyncCompleteEvent (after EOS), so MLOCK runs with the final
 * protocol sync state. We do not enforce on ChannelSyncedEvent during the initial burst.
 * When a channel is synced after the burst (e.g. first join to an empty channel), we enforce
 * on ChannelSyncedEvent so MLOCK is applied.
 */
final readonly class ChanServMlockEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelServiceActionsPort $channelServiceActions,
        private BurstCompletePort $burstCompletePort,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSyncCompleteEvent::class => ['onSyncComplete', -10],
            ChannelSyncedEvent::class => ['onChannelSynced', -10],
            ChannelModesChangedEvent::class => ['onChannelModesChanged', 255],
            ChannelMlockUpdatedEvent::class => ['onMlockUpdated', 0],
        ];
    }

    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        if (!$event->channelSetupApplicable) {
            return;
        }
        // During the initial burst, do not enforce here; wait for NetworkSyncCompleteEvent
        // so MLOCK runs with the final protocol sync state (all SJOINs/MODEs processed).
        if (!$this->burstCompletePort->isComplete()) {
            return;
        }
        $channelName = $event->channel->name->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered || !$registered->isMlockActive()) {
            return;
        }
        if ($registered->isSuspended()) {
            return;
        }
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }
        $this->enforceMlockForChannel($channelName, $view, $registered);
    }

    public function onMlockUpdated(ChannelMlockUpdatedEvent $event): void
    {
        $registered = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $registered || !$registered->isMlockActive()) {
            return;
        }
        if ($registered->isSuspended()) {
            return;
        }
        $view = $this->channelLookup->findByChannelName($event->channelName);
        if (null === $view) {
            return;
        }
        $this->enforceMlockForChannel($view->name, $view, $registered);
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();
        foreach ($channels as $registered) {
            if ($registered->isSuspended()) {
                continue;
            }
            if (!$registered->isMlockActive()) {
                continue;
            }
            $view = $this->channelLookup->findByChannelName($registered->getName());
            if (null === $view) {
                continue;
            }
            $this->enforceMlockForChannel($view->name, $view, $registered);
        }
    }

    public function onChannelModesChanged(ChannelModesChangedEvent $event): void
    {
        $ircChannel = $event->channel;
        $channelName = $ircChannel->name->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered || !$registered->isMlockActive()) {
            return;
        }
        if ($registered->isSuspended()) {
            return;
        }
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }
        $this->enforceMlockForChannel($channelName, $view, $registered);
    }

    private function enforceMlockForChannel(string $channelName, ChannelView $view, RegisteredChannel $registered): void
    {
        $mlockLetters = $this->parseMlockLetters($registered->getMlock());
        $support = $this->modeSupportProvider->getSupport();
        $currentLetters = $this->parseChannelModeLettersPreservingCase($view->modes);

        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();
        $unsetWithParam = $support->getChannelSettingModesUnsetWithParam();
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $preserveRegisteredLetter = $support->getChannelRegisteredModeLetter();
        $preservePermanentLetter = $support->getPermanentChannelModeLetter();

        $toRemove = $this->calculateModesToRemove($currentLetters, $mlockLetters, $view, $unsetWithoutParam, $unsetWithParam, $preserveRegisteredLetter, $preservePermanentLetter);
        $toAdd = $this->calculateModesToAdd($mlockLetters, $currentLetters);

        if ([] === $toRemove && [] === $toAdd) {
            return;
        }

        [$modeStr, $params] = $this->formatModeString($toRemove, $toAdd, $view, $registered, $unsetWithParam, $withParamOnSet);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, $params);
    }

    /**
     * @param list<string> $currentLetters
     * @param list<string> $mlockLetters
     * @param list<string> $unsetWithoutParam
     * @param list<string> $unsetWithParam
     *
     * @return list<string>
     */
    private function calculateModesToRemove(array $currentLetters, array $mlockLetters, ChannelView $view, array $unsetWithoutParam, array $unsetWithParam, ?string $preserveRegisteredLetter, ?string $preservePermanentLetter): array
    {
        $toRemove = [];

        foreach ($currentLetters as $letter) {
            if (in_array($letter, $mlockLetters, true)) {
                continue;
            }
            if ($letter === $preserveRegisteredLetter) {
                continue;
            }
            if ($letter === $preservePermanentLetter) {
                continue;
            }
            if (in_array($letter, $unsetWithoutParam, true) && !in_array($letter, $toRemove, true)) {
                $toRemove[] = $letter;
                continue;
            }
            if (in_array($letter, $unsetWithParam, true) && null !== $view->getModeParam($letter) && !in_array($letter, $toRemove, true)) {
                $toRemove[] = $letter;
            }
        }

        return $toRemove;
    }

    /**
     * @param list<string> $mlockLetters
     * @param list<string> $currentLetters
     *
     * @return list<string>
     */
    private function calculateModesToAdd(array $mlockLetters, array $currentLetters): array
    {
        $toAdd = [];
        foreach ($mlockLetters as $letter) {
            if (!in_array($letter, $currentLetters, true) && !in_array($letter, $toAdd, true)) {
                $toAdd[] = $letter;
            }
        }

        return $toAdd;
    }

    /**
     * @param list<string> $toRemove
     * @param list<string> $toAdd
     * @param list<string> $unsetWithParam
     * @param list<string> $withParamOnSet
     *
     * @return array{0: string, 1: list<string>}
     */
    private function formatModeString(array $toRemove, array $toAdd, ChannelView $view, RegisteredChannel $registered, array $unsetWithParam, array $withParamOnSet): array
    {
        $parts = [];
        $params = [];

        if ([] !== $toRemove) {
            $parts[] = '-' . implode('', $toRemove);
            foreach ($toRemove as $letter) {
                if (in_array($letter, $unsetWithParam, true)) {
                    $param = $view->getModeParam($letter);
                    if (null !== $param) {
                        $params[] = $param;
                    }
                }
            }
        }

        if ([] !== $toAdd) {
            $parts[] = '+' . implode('', $toAdd);
            foreach ($toAdd as $letter) {
                if (in_array($letter, $withParamOnSet, true)) {
                    $param = $registered->getMlockParam($letter);
                    if (null !== $param) {
                        $params[] = $param;
                    }
                }
            }
        }

        return [implode('', $parts), $params];
    }

    /**
     * Parses MLOCK mode string preserving case (+M and +m are different modes).
     *
     * @return list<string>
     */
    private function parseMlockLetters(string $mlockStr): array
    {
        $letters = [];
        foreach (str_split($mlockStr) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            $letters[] = $c;
        }

        return $letters;
    }

    /**
     * Parses channel mode string preserving case so we can tell +r (registered) from +R (regonly).
     *
     * @return list<string>
     */
    private function parseChannelModeLettersPreservingCase(string $modeStr): array
    {
        $letters = [];
        foreach (str_split($modeStr) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            $letters[] = $c;
        }

        return $letters;
    }
}
