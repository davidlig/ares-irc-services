<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Application\Port\ChannelServiceActionsPort;
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
    ) {}

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

        $this->kickIfUnauthorized($this->debugChannel, $uid, $user);
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
            $uid = $member['uid'];
            if ('' === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            $this->kickIfUnauthorized($this->debugChannel, $uid, $user);
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
