<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\Irc\Application\Port\In\ChannelLookupPort;

use function array_column;
use function implode;
use function str_contains;

final readonly class IrcChanNetworkActions implements ChanNetworkActions
{
    public function __construct(
        private ChannelServiceActionsPort $channelServiceActions,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
    ) {}

    /**
     * @param list<string> $modeParams
     */
    public function setChannelModes(
        string $channelName,
        string $modeString,
        array $modeParams = [],
        ?int $channelCreationTime = null,
    ): void {
        $this->channelServiceActions->setChannelModes($channelName, $modeString, $modeParams, $channelCreationTime);
    }

    public function joinChannelAsService(string $channelName, ?int $channelCreationTime = null): void
    {
        $this->channelServiceActions->joinChannelAsService($channelName, $channelCreationTime);
    }

    public function kickFromChannel(string $channelName, string $targetUid, string $reason): void
    {
        $this->channelServiceActions->kickFromChannel($channelName, $targetUid, $reason);
    }

    public function isChannelOnNetwork(string $channelName): bool
    {
        return null !== $this->channelLookup->findByChannelName($channelName);
    }

    public function getChannelTimestamp(string $channelName): ?int
    {
        $view = $this->channelLookup->findByChannelName($channelName);

        return $view?->timestamp;
    }

    /**
     * @return list<string>
     */
    public function getChannelMemberUids(string $channelName): array
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return [];
        }

        /* @var list<string> */
        return array_column($view->members, 'uid');
    }

    public function removeRegistrationModes(string $channelName): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $modesToRemove = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && str_contains($view->modes, $registeredLetter)) {
            $modesToRemove[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && str_contains($view->modes, $permanentLetter)) {
            $modesToRemove[] = $permanentLetter;
        }

        if ([] === $modesToRemove) {
            return;
        }

        $modeStr = '-' . implode('', $modesToRemove);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, [], $view->timestamp);
    }

    public function restoreRegistrationModes(string $channelName): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $modesToSet = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && !str_contains($view->modes, $registeredLetter)) {
            $modesToSet[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && !str_contains($view->modes, $permanentLetter)) {
            $modesToSet[] = $permanentLetter;
        }

        if ([] === $modesToSet) {
            return;
        }

        $modeStr = '+' . implode('', $modesToSet);
        $this->channelServiceActions->setChannelModes($channelName, $modeStr, [], $view->timestamp);
    }
}
