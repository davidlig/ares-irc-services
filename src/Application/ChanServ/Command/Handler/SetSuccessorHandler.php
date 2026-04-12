<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelSuccessorChangedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

final readonly class SetSuccessorHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $nickname = trim($value);
        if ('' === $nickname) {
            $oldSuccessorNickId = $channel->getSuccessorNickId();
            $channel->assignSuccessor(null);
            $this->channelRepository->save($channel);

            $ip = $this->decodeIp($context->sender->ipBase64);
            $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
            $performedByNickId = $context->senderAccount?->getId();

            $this->eventDispatcher->dispatch(new ChannelSuccessorChangedEvent(
                channelId: $channel->getId(),
                channelName: $channel->getName(),
                oldSuccessorNickId: $oldSuccessorNickId,
                newSuccessorNickId: null,
                performedBy: $context->sender->nick,
                performedByNickId: $performedByNickId,
                performedByIp: $ip,
                performedByHost: $host,
            ));

            $context->reply('set.successor.cleared');
            $notice = $context->trans('set.successor.notice_channel_cleared', ['%from%' => $context->sender->nick]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);

            return;
        }

        $account = $this->nickRepository->findByNick($nickname);
        if (null === $account) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $nickname]);

            return;
        }
        if (NickStatus::Suspended === $account->getStatus()) {
            $context->reply('set.successor.suspended', ['%nickname%' => $nickname]);

            return;
        }
        if (NickStatus::Registered !== $account->getStatus()) {
            $context->reply('set.successor.must_be_registered', ['%nickname%' => $nickname]);

            return;
        }
        if ($channel->isFounder($account->getId())) {
            $context->reply('set.successor.cannot_be_founder', ['%nickname%' => $nickname]);

            return;
        }

        $oldSuccessorNickId = $channel->getSuccessorNickId();
        $channel->assignSuccessor($account->getId());
        $this->channelRepository->save($channel);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new ChannelSuccessorChangedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            oldSuccessorNickId: $oldSuccessorNickId,
            newSuccessorNickId: $account->getId(),
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('set.successor.updated', ['%nickname%' => $nickname]);
        $notice = $context->trans('set.successor.notice_channel', [
            '%from%' => $context->sender->nick,
            '%nickname%' => $nickname,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);

        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
