<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSuccessorChangedEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;

use function base64_decode;
use function inet_ntop;
use function sprintf;
use function trim;

final readonly class SetSuccessorHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanUserAccountPort $accountPort,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $nickname = trim($value);
        if ('' === $nickname) {
            $this->clearSuccessor($context, $channel);

            return;
        }

        $validation = $this->validateSuccessorNick($context, $channel, $nickname);
        if (null === $validation) {
            return;
        }

        $this->performSuccessorChange($context, $channel, $nickname, $validation);
    }

    private function validateSuccessorNick(ChanServContext $context, RegisteredChannel $channel, string $nickname): ?ChanAccountView
    {
        $account = $this->accountPort->findAccountByNick($nickname);
        if (null === $account) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $nickname]);

            return null;
        }

        return $this->validateSuccessorStatus($context, $channel, $nickname, $account);
    }

    private function validateSuccessorStatus(ChanServContext $context, RegisteredChannel $channel, string $nickname, ChanAccountView $account): ?ChanAccountView
    {
        if ($account->suspended) {
            $context->reply('set.successor.suspended', ['%nickname%' => $nickname]);

            return null;
        }
        if (!$account->registered) {
            $context->reply('set.successor.must_be_registered', ['%nickname%' => $nickname]);

            return null;
        }
        if ($channel->isFounder($account->id)) {
            $context->reply('set.successor.cannot_be_founder', ['%nickname%' => $nickname]);

            return null;
        }

        return $account;
    }

    private function clearSuccessor(ChanServContext $context, RegisteredChannel $channel): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $oldSuccessorNickId = $channel->getSuccessorNickId();
        $channel->assignSuccessor(null);
        $this->channelRepository->save($channel);

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->id;

        $this->eventDispatcher->dispatch(new ChannelSuccessorChangedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            oldSuccessorNickId: $oldSuccessorNickId,
            newSuccessorNickId: null,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('set.successor.cleared');
        $notice = $context->trans('set.successor.notice_channel_cleared', ['%from%' => $sender->nick]);
        $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
    }

    private function performSuccessorChange(ChanServContext $context, RegisteredChannel $channel, string $nickname, ChanAccountView $account): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $oldSuccessorNickId = $channel->getSuccessorNickId();
        $channel->assignSuccessor($account->id);
        $this->channelRepository->save($channel);

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->id;

        $this->eventDispatcher->dispatch(new ChannelSuccessorChangedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            oldSuccessorNickId: $oldSuccessorNickId,
            newSuccessorNickId: $account->id,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('set.successor.updated', ['%nickname%' => $nickname]);
        $notice = $context->trans('set.successor.notice_channel', [
            '%from%' => $sender->nick,
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
