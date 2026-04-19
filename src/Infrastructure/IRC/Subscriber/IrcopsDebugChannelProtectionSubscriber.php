<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
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
        private OperIrcopRepositoryInterface $ircopRepo,
        private RootUserRegistry $rootRegistry,
        private RegisteredNickRepositoryInterface $nickRepo,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private string $chanservNick,
        private ?string $debugChannel,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoined', 15],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 15],
        ];
    }

    public function onUserJoined(UserJoinedChannelEvent $event): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $channelName = strtolower($event->channel->value);
        $debugChannelLower = strtolower($this->debugChannel);

        if ($channelName !== $debugChannelLower) {
            return;
        }

        $uid = $event->uid->value;

        $user = $this->userLookup->findByUid($uid);
        if (null === $user) {
            return;
        }

        $this->kickIfUnauthorized($uid, $user);
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $channelView = $this->channelLookup->findByChannelName($this->debugChannel);
        if (null === $channelView) {
            return;
        }

        foreach ($channelView->members as $member) {
            $uid = $member['uid'] ?? '';
            if ('' === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            $this->kickIfUnauthorized($uid, $user);
        }
    }

    private function kickIfUnauthorized(string $uid, SenderView $user): void
    {
        if ($user->nick === $this->chanservNick) {
            return;
        }

        if ($this->isIrcopOrRoot($user->nick, $user->isIdentified)) {
            return;
        }

        $registeredNick = $this->nickRepo->findByNick($user->nick);
        $language = null !== $registeredNick
            ? $registeredNick->getLanguage()
            : $this->defaultLanguage;

        $reason = $this->translator->trans(
            'debug_channel.kick_reason',
            [],
            'chanserv',
            $language,
        );

        $this->channelActions->kickFromChannel($this->debugChannel, $uid, $reason);

        $this->logger->info('IRCops debug channel: kicked non-IRCop user', [
            'channel' => $this->debugChannel,
            'uid' => $uid,
            'nick' => $user->nick,
        ]);
    }

    private function isIrcopOrRoot(string $nick, bool $isIdentified): bool
    {
        if (!$isIdentified) {
            return false;
        }

        if ($this->rootRegistry->isRoot($nick)) {
            return true;
        }

        $registeredNick = $this->nickRepo->findByNick($nick);

        if (null === $registeredNick) {
            return false;
        }

        $ircop = $this->ircopRepo->findByNickId($registeredNick->getId());

        return null !== $ircop;
    }
}
