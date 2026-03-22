<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When a user with MEMOREAD joins a channel, send a NOTICE about pending channel memos
 * (after ENTRYMSG; priority -10 so ChanServEntryMsgSubscriber runs first).
 */
final readonly class MemoServPendingChannelNoticeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private MemoRepositoryInterface $memoRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
        private ChanServAccessHelper $accessHelper,
        private MemoServNotifierInterface $notifier,
        private NetworkUserLookupPort $userLookup,
        private TranslatorInterface $translator,
        private string $memoservUid,
        private string $defaultLanguage = 'en',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', -10],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        if ($event->uid->value === $this->memoservUid) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channel->value));
        if (null === $channel) {
            return;
        }

        if (!$this->memoSettingsRepository->isEnabledForChannel($channel->getId())) {
            return;
        }

        $unread = $this->memoRepository->countUnreadByTargetChannel($channel->getId());
        if (0 === $unread) {
            return;
        }

        $sender = $this->userLookup->findByUid($event->uid->value);
        if (null === $sender) {
            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        if (null === $account || !$sender->isIdentified) {
            return;
        }

        $userLevel = $this->accessHelper->effectiveAccessLevel($channel, $account->getId(), true);
        $required = $this->accessHelper->getLevelValue($channel->getId(), ChannelLevel::KEY_MEMOREAD);
        if ($userLevel < $required) {
            return;
        }

        $language = $account->getLanguage() ?? $this->defaultLanguage;
        $message = $this->translator->trans('notify.channel_pending', [
            '%channel%' => $event->channel->value,
            '%count%' => $unread,
            '%bot%' => $this->notifier->getNick(),
        ], 'memoserv', $language);
        $this->notifier->sendNotice($event->uid->value, $message);
    }
}
