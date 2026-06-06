<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Subscriber;

use App\Application\ApplicationPort\ServiceUidRegistry;
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
        if ($event->uid->value === $this->uidRegistry->getUid('memoserv')) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channel->value));

        if (null === $channel || !$this->memoSettingsRepository->isEnabledForChannel($channel->getId())) {
            return;
        }

        $unread = $this->memoRepository->countUnreadByTargetChannel($channel->getId());

        $sender = 0 !== $unread ? $this->userLookup->findByUid($event->uid->value) : null;
        $account = null !== $sender ? $this->nickRepository->findByNick($sender->nick) : null;
        $hasAccess = null !== $account && $sender->isIdentified
            && $this->accessHelper->effectiveAccessLevel($channel, $account->getId(), true) >= $this->accessHelper->getLevelValue($channel->getId(), ChannelLevel::KEY_MEMOREAD);

        if (0 === $unread || !$hasAccess) {
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
