<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\Application\Shared\ServiceUidRegistry;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNotice;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeHandler;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeOutcome;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When a user with MEMOREAD joins a channel, send a NOTICE about pending channel memos
 * (after ENTRYMSG; priority -10 so ChanServEntryMsgSubscriber runs first).
 */
final readonly class MemoServPendingChannelNoticeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private GetPendingChannelNoticeHandler $getPendingChannelNotice,
        private MemoServNotifierInterface $notifier,
        private NetworkUserLookupPort $userLookup,
        private TranslatorInterface $translator,
        private ServiceUidRegistry $uidRegistry,
        private string $defaultLanguage = 'en',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', -10],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        if ($event->uid === $this->uidRegistry->getUid('memoserv')) {
            return;
        }

        $sender = $this->userLookup->findByUid($event->uid);
        if (null === $sender) {
            return;
        }

        $result = $this->getPendingChannelNotice->handle(new GetPendingChannelNotice(
            uid: $event->uid,
            nickname: $sender->nick,
            isIdentified: $sender->isIdentified,
            channelName: $event->channelName,
        ));
        if (GetPendingChannelNoticeOutcome::PendingMemos !== $result->outcome) {
            return;
        }

        $language = '' !== $result->language ? $result->language : $this->defaultLanguage;
        $message = $this->translator->trans('notify.channel_pending', [
            '%channel%' => $result->channelName,
            '%count%' => $result->unreadCount,
            '%bot%' => $this->notifier->getNick(),
        ], 'memoserv', $language);
        $this->notifier->sendNotice($result->uid, $message);
    }
}
