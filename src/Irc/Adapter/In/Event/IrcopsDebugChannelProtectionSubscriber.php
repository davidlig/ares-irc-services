<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelIrcopOnlyUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelPendingDeletionEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRestoredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\OperatorOnlyChannelControl;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\ChannelSettingsChangedEvent;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function strtolower;

final readonly class IrcopsDebugChannelProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelServiceActionsPort $channelActions,
        private ChannelLookupPort $channelLookup,
        private NetworkUserLookupPort $userLookup,
        private NickAccountQuery $nickAccounts,
        private OperatorAuthorizationQuery $authorization,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private string $chanservNick,
        private ?string $debugChannel,
        private LoggerInterface $logger,
        private ?ChannelProjectionQuery $registeredChannels = null,
        private ?OperatorOnlyChannelControl $operatorOnlyControl = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoined', 15],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 15],
            ChannelIrcopOnlyUpdatedEvent::class => 'onIrcopOnlyUpdated',
            ChannelSuspendedEvent::class => 'onChannelSuspended',
            ChannelUnsuspendedEvent::class => 'onChannelUnsuspended',
            ChannelPendingDeletionEvent::class => 'onChannelPendingDeletion',
            ChannelRestoredEvent::class => 'onChannelRestored',
            ChannelDropEvent::class => 'onChannelDrop',
            ChannelSettingsChangedEvent::class => 'onSettingsChanged',
        ];
    }

    public function onUserJoined(UserJoinedChannelEvent $event): void
    {
        $channelName = strtolower($event->channel->value);
        if (!$this->isProtected($channelName)) {
            return;
        }

        $uid = $event->uid->value;

        $user = $this->userLookup->findByUid($uid);
        if (null === $user) {
            return;
        }

        $this->kickIfUnauthorized($event->channel->value, $uid, $user);
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        if (null !== $this->debugChannel && '' !== $this->debugChannel) {
            $this->kickCurrentMembers($this->debugChannel);
        }
        foreach ($this->registeredChannels?->all() ?? [] as $channel) {
            if (!$channel->ircopOnly || $channel->forbidden || $channel->suspended || $channel->pendingDeletion) {
                continue;
            }
            $this->operatorOnlyControl?->activate($channel->name);
            if (null === $this->debugChannel || strtolower($channel->name) !== strtolower($this->debugChannel)) {
                $this->kickCurrentMembers($channel->name);
            }
        }
    }

    public function onIrcopOnlyUpdated(ChannelIrcopOnlyUpdatedEvent $event): void
    {
        $channel = $this->registeredChannels?->findByName(strtolower($event->channelName));
        if (null !== $channel && $channel->ircopOnly && !$channel->forbidden && !$channel->suspended && !$channel->pendingDeletion) {
            $this->operatorOnlyControl?->activate($channel->name);
            $this->kickCurrentMembers($channel->name);

            return;
        }
        $this->operatorOnlyControl?->deactivate($event->channelName);
    }

    public function onChannelSuspended(ChannelSuspendedEvent $event): void
    {
        $this->deactivateIfConfigured($event->channelName);
    }

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        if ($this->registeredChannels?->findByName($event->channelNameLower)?->ircopOnly) {
            $this->onIrcopOnlyUpdated(new ChannelIrcopOnlyUpdatedEvent($event->channelName));
        }
    }

    public function onChannelPendingDeletion(ChannelPendingDeletionEvent $event): void
    {
        $this->deactivateIfConfigured($event->channelName);
    }

    public function onChannelRestored(ChannelRestoredEvent $event): void
    {
        if ($this->registeredChannels?->findByName($event->channelNameLower)?->ircopOnly) {
            $this->onIrcopOnlyUpdated(new ChannelIrcopOnlyUpdatedEvent($event->channelName));
        }
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        if ($event->ircopOnly) {
            $this->operatorOnlyControl?->deactivate($event->channelName);
        }
    }

    public function onSettingsChanged(ChannelSettingsChangedEvent $event): void
    {
        $channel = $this->registeredChannels?->findByName(strtolower($event->channelName));
        if (null !== $channel && $channel->ircopOnly && !$channel->forbidden && !$channel->suspended && !$channel->pendingDeletion) {
            $this->operatorOnlyControl?->ensureMode($channel->name);
        }
    }

    private function isProtected(string $channelName): bool
    {
        if (null !== $this->debugChannel && '' !== $this->debugChannel && $channelName === strtolower($this->debugChannel)) {
            return true;
        }
        $channel = $this->registeredChannels?->findByName($channelName);

        return null !== $channel && $channel->ircopOnly && !$channel->forbidden && !$channel->suspended && !$channel->pendingDeletion;
    }

    private function deactivateIfConfigured(string $channelName): void
    {
        if ($this->registeredChannels?->findByName(strtolower($channelName))?->ircopOnly) {
            $this->operatorOnlyControl?->deactivate($channelName);
        }
    }

    private function kickCurrentMembers(string $channelName): void
    {
        $channelView = $this->channelLookup->findByChannelName($channelName);
        if (null === $channelView) {
            return;
        }

        foreach ($channelView->members as $member) {
            $uid = $member['uid'];
            if ('' === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            $this->kickIfUnauthorized($channelName, $uid, $user);
        }
    }

    private function kickIfUnauthorized(string $channelName, string $uid, SenderView $user): void
    {
        if ($user->nick === $this->chanservNick) {
            return;
        }

        if ($this->isAuthorized($user)) {
            return;
        }

        $account = $this->nickAccounts->findAccountByNick($user->nick);
        $language = null !== $account
            ? $account->language
            : $this->defaultLanguage;

        $reason = $this->translator->trans(
            'debug_channel.kick_reason',
            [],
            'chanserv',
            $language,
        );

        $this->channelActions->kickFromChannel($channelName, $uid, $reason);

        $this->logger->info('IRCops debug channel: kicked non-IRCop user', [
            'channel' => $channelName,
            'uid' => $uid,
            'nick' => $user->nick,
        ]);
    }

    private function isAuthorized(SenderView $user): bool
    {
        $accountId = $user->isIdentified ? $this->nickAccounts->findIdByNick($user->nick) : null;

        return $this->authorization->ircOperator(new OperatorActor(
            $user->nick,
            $accountId,
            $user->isIdentified,
            $user->isOper,
        ))->granted;
    }
}
