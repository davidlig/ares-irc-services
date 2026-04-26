<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;
use function strtolower;

/**
 * Joins the debug channel when services connect (if configured) and applies
 * registered channel policies (modes, topic, ranks) after sync completes.
 *
 * When the IRCOPS_DEBUG_CHANNEL is a registered ChanServ channel but was NOT
 * received in the IRCd burst (e.g. empty channel, only ChanServ inside),
 * the internal ChannelRepository never learns about it, so the standard
 * ChanServ reconciliation subscribers skip it. This subscriber fills that gap
 * by directly applying +r/+P, MLOCK, topic, and member rank sync after EOS.
 */
final readonly class DebugChannelJoinSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<ServiceDebugNotifierInterface> $debugNotifiers
     */
    public function __construct(
        private iterable $debugNotifiers,
        private ?string $debugChannel,
        private RegisteredChannelRepositoryInterface $registeredChannelRepository,
        private ChannelLookupPort $channelLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private string $chanservUid,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', -30],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        foreach ($this->debugNotifiers as $notifier) {
            $notifier->ensureChannelJoined();
        }
    }

    /**
     * After network sync, apply registered channel policies to the debug channel
     * if it is registered but was not processed by the standard ChanServ
     * reconciliation (because it wasn't in the IRCd burst so the internal
     * ChannelRepository doesn't know about it).
     */
    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $channelNameLower = strtolower($this->debugChannel);

        $registered = $this->registeredChannelRepository->findByChannelName($channelNameLower);
        if (null === $registered || $registered->isSuspended() || $registered->isForbidden()) {
            return;
        }

        $view = $this->channelLookup->findByChannelName($this->debugChannel);
        if (null !== $view) {
            return;
        }

        $this->applyRegisteredChannelSetup($registered);
    }

    private function applyRegisteredChannelSetup(
        \App\Domain\ChanServ\Entity\RegisteredChannel $registered,
    ): void {
        $channelName = $this->debugChannel;
        $modeSupport = $this->modeSupportProvider->getSupport();

        $this->applyRegisteredAndPermanentModes($channelName, $modeSupport);
        $this->applyMlock($channelName, $registered, $modeSupport);
        $this->applyTopic($channelName, $registered);
        $this->applyChanServRank($channelName);

        $this->logger->info('Debug channel: applied registered channel setup', [
            'channel' => $channelName,
        ]);
    }

    private function applyRegisteredAndPermanentModes(
        string $channelName,
        \App\Application\Port\ChannelModeSupportInterface $modeSupport,
    ): void {
        $modesToSet = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter) {
            $modesToSet[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter) {
            $modesToSet[] = $permanentLetter;
        }

        if ([] === $modesToSet) {
            return;
        }

        $modeStr = '+' . implode('', $modesToSet);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, []);
        $this->logger->debug('Debug channel: set registered/permanent modes', [
            'channel' => $channelName,
            'modes' => $modeStr,
        ]);
    }

    private function applyMlock(
        string $channelName,
        \App\Domain\ChanServ\Entity\RegisteredChannel $registered,
        \App\Application\Port\ChannelModeSupportInterface $modeSupport,
    ): void {
        if (!$registered->isMlockActive()) {
            return;
        }

        $mlockStr = $registered->getMlock();
        if ('' === $mlockStr) {
            return;
        }

        $mlockLetters = [];
        foreach (str_split($mlockStr) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            $mlockLetters[] = $c;
        }

        if ([] === $mlockLetters) {
            return;
        }

        $modeStr = '+' . implode('', $mlockLetters);
        $mlockParams = [];

        $withParamOnSet = $modeSupport->getChannelSettingModesWithParamOnSet();
        foreach ($mlockLetters as $letter) {
            if (in_array($letter, $withParamOnSet, true)) {
                $param = $registered->getMlockParam($letter);
                if (null !== $param) {
                    $mlockParams[] = $param;
                }
            }
        }

        $this->channelServiceActions->setChannelModes($channelName, $modeStr, $mlockParams);
        $this->logger->debug('Debug channel: applied MLOCK', [
            'channel' => $channelName,
            'modes' => $modeStr,
        ]);
    }

    private function applyTopic(
        string $channelName,
        \App\Domain\ChanServ\Entity\RegisteredChannel $registered,
    ): void {
        $storedTopic = $registered->getTopic();
        if (null === $storedTopic) {
            return;
        }

        $this->channelServiceActions->setChannelTopic($channelName, $storedTopic);
        $this->logger->debug('Debug channel: applied stored topic', [
            'channel' => $channelName,
        ]);
    }

    private function applyChanServRank(string $channelName): void
    {
        $supported = $this->modeSupportProvider->getSupport()->getSupportedPrefixModes();
        $maxPrefix = 'o';
        $prefixOrder = ['q', 'a', 'o', 'h', 'v'];
        foreach ($prefixOrder as $letter) {
            if (in_array($letter, $supported, true)) {
                $maxPrefix = $letter;
                break;
            }
        }

        $this->channelServiceActions->setChannelMemberMode($channelName, $this->chanservUid, $maxPrefix, true);
        $this->logger->debug('Debug channel: set ChanServ rank', [
            'channel' => $channelName,
            'uid' => $this->chanservUid,
            'mode' => '+' . $maxPrefix,
        ]);
    }
}
