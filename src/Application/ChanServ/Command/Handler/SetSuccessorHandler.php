<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;

final readonly class SetSuccessorHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $nickname = trim($value);
        if ('' === $nickname) {
            $channel->assignSuccessor(null);
            $this->channelRepository->save($channel);
            $context->reply('set.successor.cleared');
            $notice = $context->trans('set.successor.notice_channel_cleared', ['%from%' => $context->sender->nick]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);

            return;
        }

        $account = $this->nickRepository->findByNick($nickname);
        if (null === $account) {
            $context->reply('error.nick_not_registered', ['%nick%' => $nickname]);

            return;
        }
        if (NickStatus::Suspended === $account->getStatus()) {
            $context->reply('set.successor.suspended', ['%nick%' => $nickname]);

            return;
        }
        if (NickStatus::Registered !== $account->getStatus()) {
            $context->reply('set.successor.must_be_registered', ['%nick%' => $nickname]);

            return;
        }
        if ($channel->isFounder($account->getId())) {
            $context->reply('set.successor.cannot_be_founder', ['%nick%' => $nickname]);

            return;
        }

        $channel->assignSuccessor($account->getId());
        $this->channelRepository->save($channel);
        $context->reply('set.successor.updated', ['%nick%' => $nickname]);
        $notice = $context->trans('set.successor.notice_channel', [
            '%from%' => $context->sender->nick,
            '%nick%' => $nickname,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
    }
}
