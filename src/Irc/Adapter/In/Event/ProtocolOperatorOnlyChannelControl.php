<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\OperatorOnlyChannelControl;
use App\Irc\Application\Port\In\OperatorOnlyModeManagedByOptions;
use App\Irc\Application\Port\In\ServiceUidRegistry;

use function array_any;
use function str_contains;
use function strtolower;

/** Channel modes owned by channel options are applied by the protocol adapter. */
final readonly class ProtocolOperatorOnlyChannelControl implements OperatorOnlyChannelControl
{
    public function __construct(
        private ChannelServiceActionsPort $actions,
        private ChannelLookupPort $channels,
        private ServiceUidRegistry $serviceUids,
        private ActiveProtocolModuleHolderInterface $moduleHolder,
        private ?string $debugChannel,
    ) {}

    public function activate(string $channelName): void
    {
        $view = $this->channels->findByChannelName($channelName);
        if (!$this->hasChanServ($view)) {
            $this->actions->joinChannelAsService($channelName, $view?->timestamp);
        }
        $this->ensureMode($channelName);
    }

    public function deactivate(string $channelName): void
    {
        $view = $this->channels->findByChannelName($channelName);
        if (null === $view) {
            return;
        }
        if (!$this->isUdb() && str_contains($view->modes, 'O')) {
            $this->actions->setChannelModes($channelName, '-O', [], $view->timestamp);
        }
        if ((null === $this->debugChannel || strtolower($channelName) !== strtolower($this->debugChannel)) && $this->hasChanServ($view)) {
            $this->actions->partChannelAsService($channelName);
        }
    }

    public function ensureMode(string $channelName): void
    {
        if ($this->isUdb()) {
            return;
        }
        $view = $this->channels->findByChannelName($channelName);
        if (null === $view || !str_contains($view->modes, 'O')) {
            $this->actions->setChannelModes($channelName, '+O', [], $view?->timestamp);
        }
    }

    private function hasChanServ(?ChannelView $view): bool
    {
        $uid = $this->serviceUids->getUid('chanserv');
        if (null === $view || null === $uid || '' === $uid) {
            return false;
        }

        return array_any($view->members, static fn (array $member): bool => $member['uid'] === $uid);
    }

    private function isUdb(): bool
    {
        return $this->moduleHolder->getProtocolModule() instanceof OperatorOnlyModeManagedByOptions;
    }
}
