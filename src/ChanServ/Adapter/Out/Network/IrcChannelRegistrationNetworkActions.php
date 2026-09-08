<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelRegistrationNetworkActions;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelServiceActionsPort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function implode;
use function str_contains;
use function strtolower;

final readonly class IrcChannelRegistrationNetworkActions implements ChannelRegistrationNetworkActions
{
    public function __construct(
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelLookupPort $channelLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function supportsRegisteredMode(): bool
    {
        return null !== $this->modeSupportProvider->getSupport()->getChannelRegisteredModeLetter();
    }

    public function supportsPermanentMode(): bool
    {
        return null !== $this->modeSupportProvider->getSupport()->getPermanentChannelModeLetter();
    }

    public function applyRegistrationModes(string $channelName): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }

        $support = $this->modeSupportProvider->getSupport();
        $modesToSet = [];
        $registeredLetter = $support->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && !str_contains($view->modes, $registeredLetter)) {
            $modesToSet[] = $registeredLetter;
        }

        $permanentLetter = $support->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && !str_contains($view->modes, $permanentLetter)) {
            $modesToSet[] = $permanentLetter;
        }

        if ([] === $modesToSet) {
            return;
        }

        $modeString = '+' . implode('', $modesToSet);
        $this->channelServiceActions->setChannelModes($channelName, $modeString, []);
        $this->logger->debug('ChanServ set modes on channel registration', [
            'channel' => $channelName,
            'modes' => $modeString,
        ]);
    }

    public function removeRegistrationModesAfterDrop(string $channelName, string $reason): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }

        $support = $this->modeSupportProvider->getSupport();
        $modesToRemove = [];
        $registeredLetter = $support->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && str_contains($view->modes, $registeredLetter)) {
            $modesToRemove[] = $registeredLetter;
        }

        $permanentLetter = $support->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && str_contains($view->modes, $permanentLetter)) {
            $modesToRemove[] = $permanentLetter;
        }

        if ([] === $modesToRemove) {
            return;
        }

        $modeString = '-' . implode('', $modesToRemove);
        $this->channelServiceActions->setChannelModes($channelName, $modeString, []);
        $this->logger->debug('ChanServ removed modes on channel drop', [
            'channel' => $channelName,
            'modes' => $modeString,
            'reason' => $reason,
        ]);
    }

    public function ensureRegisteredModeOnChannelSync(string $channelName): void
    {
        $registeredLetter = $this->modeSupportProvider->getSupport()->getChannelRegisteredModeLetter();
        if (null === $registeredLetter) {
            return;
        }

        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view || str_contains($view->modes, $registeredLetter)) {
            return;
        }

        $this->channelServiceActions->setChannelModes($channelName, '+' . $registeredLetter, []);
        $this->logger->debug('ChanServ set +' . $registeredLetter . ' (channel registered) on sync', ['channel' => $channelName]);
    }

    public function reconcileRegisteredMode(array $registeredNames, array $eligibleChannelNames): void
    {
        $registeredLetter = $this->modeSupportProvider->getSupport()->getChannelRegisteredModeLetter();
        if (null === $registeredLetter) {
            return;
        }

        $this->ensureModeOnEligibleChannels($eligibleChannelNames, $registeredLetter, 'registered');
        $this->removeModeFromUnregisteredChannels($registeredNames, $registeredLetter, 'registered');
    }

    public function reconcilePermanentMode(array $registeredNames, array $eligibleChannelNames): void
    {
        $permanentLetter = $this->modeSupportProvider->getSupport()->getPermanentChannelModeLetter();
        if (null === $permanentLetter) {
            return;
        }

        $this->ensureModeOnEligibleChannels($eligibleChannelNames, $permanentLetter, 'permanent');
        $this->removeModeFromUnregisteredChannels($registeredNames, $permanentLetter, 'permanent');
    }

    /** @param list<string> $eligibleChannelNames */
    private function ensureModeOnEligibleChannels(array $eligibleChannelNames, string $modeLetter, string $description): void
    {
        foreach ($eligibleChannelNames as $channelName) {
            $view = $this->channelLookup->findByChannelName($channelName);
            if (null === $view || str_contains($view->modes, $modeLetter)) {
                continue;
            }

            $this->channelServiceActions->setChannelModes($view->name, '+' . $modeLetter, []);
            $this->logger->debug(
                'ChanServ set +' . $modeLetter . ' (' . $description . ') missing on registered channel',
                ['channel' => $view->name],
            );
        }
    }

    /** @param array<string, true> $registeredNames */
    private function removeModeFromUnregisteredChannels(array $registeredNames, string $modeLetter, string $description): void
    {
        foreach ($this->channelLookup->listAll() as $view) {
            if (isset($registeredNames[strtolower($view->name)]) || !str_contains($view->modes, $modeLetter)) {
                continue;
            }

            $this->channelServiceActions->setChannelModes($view->name, '-' . $modeLetter, []);
            $this->logger->debug(
                'ChanServ removed -' . $modeLetter . ' (' . $description . ') from unregistered channel',
                ['channel' => $view->name],
            );
        }
    }
}
