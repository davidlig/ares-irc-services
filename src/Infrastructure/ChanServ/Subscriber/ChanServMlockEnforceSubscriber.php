<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

/**
 * Enforces MLOCK on registered channels: locked modes are fixed; any other
 * channel mode is stripped so the channel has exactly the MLOCK modes (plus +r
 * if set by us). E.g. MLOCK +nt → channel stays +nt (or +ntr); +m, +R, +S etc. are removed.
 *
 * Burst: we enforce on NetworkSyncCompleteEvent (after rejoin).
 * When a channel is created or synced (e.g. after our JOIN created it), we enforce on ChannelSyncedEvent
 * so channels that did not exist at sync time get MLOCK applied.
 */
final readonly class ChanServMlockEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelServiceActionsPort $channelServiceActions,
        private LoggerInterface $logger = new NullLogger(),
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
        $channelName = $event->channel->name->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered || !$registered->isMlockActive()) {
            return;
        }
        if ('' === $registered->getMlock()) {
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
            if (!$registered->isMlockActive()) {
                continue;
            }
            $mlockStr = $registered->getMlock();
            if ('' === $mlockStr) {
                $this->logger->debug('ChanServ MLOCK skip: empty mlock', ['channel' => $registered->getName()]);
                continue;
            }
            $view = $this->channelLookup->findByChannelName($registered->getName());
            if (null === $view) {
                $this->logger->debug('ChanServ MLOCK skip: channel not on network (no view)', [
                    'channel' => $registered->getName(),
                ]);
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
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }
        $this->enforceMlockForChannel($channelName, $view, $registered);
    }

    private function enforceMlockForChannel(string $channelName, ChannelView $view, RegisteredChannel $registered): void
    {
        $mlockStr = $registered->getMlock();
        if ('' === $mlockStr) {
            return;
        }

        $mlockLetters = $this->parseMlockLetters($mlockStr);
        if ([] === $mlockLetters) {
            return;
        }

        $support = $this->modeSupportProvider->getSupport();
        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();
        $unsetWithParam = $support->getChannelSettingModesUnsetWithParam();
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $currentLettersRaw = $this->parseChannelModeLettersPreservingCase($view->modes);
        $currentLettersLower = array_map('strtolower', $currentLettersRaw);

        // Remove modes not in MLOCK (except +r channel-registered). Mode letters are case-sensitive.
        // For modes that need param to unset (k, L), only add to toRemove if we have the stored param.
        $toRemove = [];
        foreach ($currentLettersRaw as $letter) {
            if (in_array(strtolower($letter), $mlockLetters, true)) {
                continue;
            }
            if ('r' === $letter) {
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

        $toAdd = [];
        foreach ($mlockLetters as $letter) {
            if (!in_array($letter, $currentLettersLower, true) && !in_array($letter, $toAdd, true)) {
                $toAdd[] = $letter;
            }
        }

        if ([] === $toRemove && [] === $toAdd) {
            $this->logger->debug('ChanServ MLOCK: no change needed', [
                'channel' => $channelName,
                'viewModes' => $view->modes,
                'mlockLetters' => $mlockLetters,
            ]);

            return;
        }

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
        $modeStr = implode('', $parts);

        $this->channelServiceActions->setChannelModes($channelName, $modeStr, $params);
        $this->logger->info('ChanServ MLOCK enforced', [
            'channel' => $channelName,
            'viewModes' => $view->modes,
            'toRemove' => $toRemove,
            'toAdd' => $toAdd,
            'sent' => $modeStr,
        ]);
    }

    /**
     * @return list<string>
     */
    private function parseMlockLetters(string $mlockStr): array
    {
        $letters = [];
        foreach (str_split($mlockStr) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            $letters[] = strtolower($c);
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
