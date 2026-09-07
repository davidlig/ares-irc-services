<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When a user identifies with NickServ, notify them if they have unread memos.
 */
final readonly class MemoServNickIdentifiedNoticeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private MemoUserAccountPort $userAccountPort,
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
        $unread = $this->memoRepository->countUnreadByTargetNick($event->nickId);
        if (0 === $unread) {
            return;
        }

        $language = $this->userAccountPort->getLanguage($event->nickId);
        $message = $this->translator->trans('notify.nick_pending', ['%count%' => $unread, '%bot%' => $this->notifier->getNick()], 'memoserv', $language);
        $this->notifier->sendNotice($event->uid, $message);
    }
}
