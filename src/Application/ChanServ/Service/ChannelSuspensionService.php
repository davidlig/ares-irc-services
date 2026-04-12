<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Service;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;
use function str_contains;

/**
 * Handles IRC-level enforcement when a channel is suspended or unsuspended.
 *
 * Suspension: removes +rP modes and sends a notice to the channel.
 * Unsuspension: restores +rP modes (MLOCK and rank enforcement are handled
 * by existing subscribers on the next mode sync).
 */
readonly class ChannelSuspensionService
{
    public function __construct(
        private ChannelServiceActionsPort $channelServiceActions,
        private ChanServNotifierInterface $notifier,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private TranslatorInterface $translator,
        private string $defaultLanguage = 'en',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function enforceSuspension(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();

        $this->removeRegistrationModes($channelName);
        $this->sendSuspensionNotice($channel);
    }

    public function liftSuspension(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();

        $this->restoreRegistrationModes($channelName);
    }

    private function removeRegistrationModes(string $channelName): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            $this->logger->debug(sprintf(
                'ChannelSuspension: channel %s not found on network, skipping mode removal',
                $channelName,
            ));

            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $modesToRemove = $this->collectRegistrationModes($view->modes, $modeSupport);

        if ([] === $modesToRemove) {
            return;
        }

        $modeStr = '-' . implode('', $modesToRemove);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, []);

        $this->logger->info(sprintf(
            'ChannelSuspension: removed modes %s from %s',
            $modeStr,
            $channelName,
        ));
    }

    private function restoreRegistrationModes(string $channelName): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            $this->logger->debug(sprintf(
                'ChannelSuspension: channel %s not found on network, skipping mode restore',
                $channelName,
            ));

            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $modesToSet = $this->collectMissingRegistrationModes($view->modes, $modeSupport);

        if ([] === $modesToSet) {
            return;
        }

        $modeStr = '+' . implode('', $modesToSet);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, []);

        $this->logger->info(sprintf(
            'ChannelSuspension: restored modes %s on %s',
            $modeStr,
            $channelName,
        ));
    }

    private function sendSuspensionNotice(RegisteredChannel $channel): void
    {
        $message = $this->translator->trans(
            'suspend.notice_channel',
            ['%reason%' => $channel->getSuspendedReason() ?? ''],
            'chanserv',
            $this->defaultLanguage,
        );
        $this->notifier->sendNoticeToChannel($channel->getName(), $message);

        $this->logger->info(sprintf(
            'ChannelSuspension: sent suspension notice to %s',
            $channel->getName(),
        ));
    }

    /**
     * @return list<string>
     */
    private function collectRegistrationModes(string $currentModes, ChannelModeSupportInterface $modeSupport): array
    {
        $modes = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && str_contains($currentModes, $registeredLetter)) {
            $modes[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && str_contains($currentModes, $permanentLetter)) {
            $modes[] = $permanentLetter;
        }

        return $modes;
    }

    /**
     * @return list<string>
     */
    private function collectMissingRegistrationModes(string $currentModes, ChannelModeSupportInterface $modeSupport): array
    {
        $modes = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && !str_contains($currentModes, $registeredLetter)) {
            $modes[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && !str_contains($currentModes, $permanentLetter)) {
            $modes[] = $permanentLetter;
        }

        return $modes;
    }
}
