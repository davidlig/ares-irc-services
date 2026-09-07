<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\Shared\ServiceUidRegistry;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function strtolower;

/**
 * When a user with MEMOREAD joins a channel, send a NOTICE about pending channel memos
 * (after ENTRYMSG; priority -10 so ChanServEntryMsgSubscriber runs first).
 */
final readonly class MemoServPendingChannelNoticeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoUserAccountPort $userAccountPort,
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
        $account = null !== $sender ? $this->userAccountPort->findAccountByNick($sender->nick) : null;
        $hasAccess = null !== $account && $sender->isIdentified
            && $this->accessHelper->effectiveAccessLevel($channel, $account->id, true) >= $this->accessHelper->getLevelValue($channel->getId(), ChannelLevel::KEY_MEMOREAD);

        if (0 === $unread || !$hasAccess) {
            return;
        }

        $language = '' !== $account->language ? $account->language : $this->defaultLanguage;
        $message = $this->translator->trans('notify.channel_pending', [
            '%channel%' => $event->channel->value,
            '%count%' => $unread,
            '%bot%' => $this->notifier->getNick(),
        ], 'memoserv', $language);
        $this->notifier->sendNotice($event->uid->value, $message);
    }
}
