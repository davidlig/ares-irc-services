<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function implode;
use function in_array;
use function sprintf;
use function str_split;

/**
 * Restores a channel's IRC-level state when its suspension is lifted.
 *
 * On unsuspend (manual via UNSUSPEND command or automatic from expired suspension):
 * 1. If the channel does not exist on the IRC network (e.g. all users were kicked
 *    during suspension and the empty channel was destroyed), ChanServ joins it first
 *    to recreate it.
 * 2. Restores +rP modes
 * 3. Applies MLOCK if the channel has MLOCK active
 */
final readonly class ChanServUnsuspendSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelSuspensionService $suspensionService,
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
            ChannelUnsuspendedEvent::class => ['onChannelUnsuspended', 0],
        ];
    }

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        $channel = $this->channelRepository->findByChannelName($event->channelNameLower);
        if (null === $channel) {
            return;
        }

        $channelName = $channel->getName();

        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            $this->logger->info(sprintf(
                'ChanServUnsuspend: channel %s not found on network, joining to recreate it',
                $channelName,
            ));
            $this->channelServiceActions->joinChannelAsService($channelName);
        }

        $this->suspensionService->liftSuspension($channel);

        if ($channel->isMlockActive()) {
            $this->applyMlock($channel);
        }
    }

    private function applyMlock(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            $this->logger->debug(sprintf(
                'ChanServUnsuspend: channel %s not found on network, skipping MLOCK apply',
                $channelName,
            ));

            return;
        }

        $mlockString = $channel->getMlock();
        if ('' === $mlockString) {
            return;
        }

        $support = $this->modeSupportProvider->getSupport();
        $mlockLetters = $this->parseMlockLetters($mlockString);
        $currentLetters = $this->parseModeLetters($view->modes);

        $toAdd = [];
        foreach ($mlockLetters as $letter) {
            if (!in_array($letter, $currentLetters, true)) {
                $toAdd[] = $letter;
            }
        }

        if ([] === $toAdd) {
            return;
        }

        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $params = [];
        foreach ($toAdd as $letter) {
            if (in_array($letter, $withParamOnSet, true)) {
                $param = $channel->getMlockParam($letter);
                if (null !== $param && '' !== $param) {
                    $params[] = $param;
                }
            }
        }

        $modeStr = '+' . implode('', $toAdd);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, $params);

        $this->logger->info(sprintf(
            'ChanServUnsuspend: applied MLOCK %s on %s',
            $modeStr,
            $channelName,
        ));
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
            $letters[] = $c;
        }

        return $letters;
    }

    /**
     * @return list<string>
     */
    private function parseModeLetters(string $modeStr): array
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
