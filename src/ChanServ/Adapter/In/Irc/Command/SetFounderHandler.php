<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\TransferFounder\FounderTransferActor;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounder;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderHandlerInterface;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderResult;
use App\ChanServ\Application\UseCase\TransferFounder\TransferFounderOutcome;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;

use function base64_decode;
use function inet_ntop;
use function sprintf;
use function trim;

final readonly class SetFounderHandler implements SetOptionHandlerInterface
{
    public function __construct(private TransferChannelFounderHandlerInterface $handler) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $sender = $context->sender;
        $actor = null;
        if (null !== $sender) {
            $actor = new FounderTransferActor(
                nickname: $sender->nick,
                registeredNickId: $context->senderAccount?->id,
                ipAddress: $this->decodeIp($sender->ipBase64),
                hostmask: sprintf('%s@%s', $sender->ident, $sender->hostname),
            );
        }

        $token = isset($context->args[3]) ? trim($context->args[3]) : null;
        $result = $this->handler->handle(new TransferChannelFounder(
            channel: $channel,
            targetNickname: trim($value),
            token: $token,
            actor: $actor,
            founderEquivalent: $context->isLevelFounder,
            serviceNickname: $context->getNotifier()->getNick(),
            locale: $context->getLanguage(),
            requestedAt: new DateTimeImmutable(),
        ));
        if (null === $actor) {
            return;
        }

        $this->present($context, $channel, $result, $actor->nickname);
    }

    private function present(
        ChanServContext $context,
        RegisteredChannel $channel,
        TransferChannelFounderResult $result,
        string $actorNickname,
    ): void {
        match ($result->outcome) {
            TransferFounderOutcome::MissingTarget => $context->reply('set.founder.syntax'),
            TransferFounderOutcome::TargetNotFound => $context->reply('error.nick_not_registered', ['%nickname%' => $result->targetNickname]),
            TransferFounderOutcome::TargetSuspended => $context->reply('set.founder.suspended', ['%nickname%' => $result->targetNickname]),
            TransferFounderOutcome::TargetNotRegistered => $context->reply('set.founder.must_be_registered', ['%nickname%' => $result->targetNickname]),
            TransferFounderOutcome::SameFounder => $context->reply('set.founder.cannot_be_self'),
            TransferFounderOutcome::TargetIsSuccessor => $context->reply('set.founder.cannot_be_successor'),
            TransferFounderOutcome::ChannelLimitReached => $context->reply('set.founder.limit_exceeded', [
                '%nickname%' => $result->targetNickname,
                '%max%' => (string) $result->maximumChannelsPerNick,
            ]),
            TransferFounderOutcome::CurrentFounderWithoutEmail => $context->reply('set.founder.no_email'),
            TransferFounderOutcome::Throttled => $context->reply('set.founder.throttled'),
            TransferFounderOutcome::TokenSent => $context->reply('set.founder.token_sent', ['%email_hint%' => $result->emailHint]),
            TransferFounderOutcome::InvalidToken => $context->reply('set.founder.invalid_token'),
            TransferFounderOutcome::MailDeliveryFailed => $context->reply('error.mail_failed'),
            TransferFounderOutcome::Updated => $this->presentUpdated($context, $channel, $result, $actorNickname),
            TransferFounderOutcome::Ignored => null,
        };
    }

    private function presentUpdated(
        ChanServContext $context,
        RegisteredChannel $channel,
        TransferChannelFounderResult $result,
        string $actorNickname,
    ): void {
        $context->reply('set.founder.updated', ['%nickname%' => $result->targetNickname]);
        $notice = $context->trans('set.founder.notice_channel', [
            '%from%' => $actorNickname,
            '%nickname%' => $result->targetNickname,
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
