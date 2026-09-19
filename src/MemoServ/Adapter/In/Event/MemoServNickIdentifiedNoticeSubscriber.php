<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNotice;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeHandler;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeOutcome;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When a user identifies with NickServ, notify them if they have unread memos.
 */
final readonly class MemoServNickIdentifiedNoticeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private GetPendingNickNoticeHandler $getPendingNickNotice,
        private MemoServNotifierInterface $notifier,
        private TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickIdentifiedEvent::class => ['onNickIdentified', 0],
        ];
    }

    public function onNickIdentified(NickIdentifiedEvent $event): void
    {
        $result = $this->getPendingNickNotice->handle(new GetPendingNickNotice($event->nickId, $event->uid));
        if (GetPendingNickNoticeOutcome::PendingMemos !== $result->outcome) {
            return;
        }

        $message = $this->translator->trans('notify.nick_pending', ['%count%' => $result->unreadCount, '%bot%' => $this->notifier->getNick()], 'memoserv', $result->language);
        $this->notifier->sendNotice($result->uid, $message);
    }
}
