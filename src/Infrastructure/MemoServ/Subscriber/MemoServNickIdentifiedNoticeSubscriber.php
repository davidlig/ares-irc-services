<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Subscriber;

use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When a user identifies with NickServ, notify them if they have unread memos.
 */
final readonly class MemoServNickIdentifiedNoticeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private MemoServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $defaultLanguage = 'en',
    ) {
    }

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

        $account = $this->nickRepository->findById($event->nickId);
        $language = null !== $account ? $account->getLanguage() : $this->defaultLanguage;
        $message = $this->translator->trans('notify.nick_pending', ['%count%' => $unread, '%bot%' => $this->notifier->getNick()], 'memoserv', $language);
        $this->notifier->sendNotice($event->uid, $message);
    }
}
