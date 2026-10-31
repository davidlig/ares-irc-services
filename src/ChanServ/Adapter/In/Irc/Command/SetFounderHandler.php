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
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function base64_decode;
use function ceil;
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
                clientKey: match (true) {
                    '' !== $sender->ipBase64 && '*' !== $sender->ipBase64 => 'ip:' . $sender->ipBase64,
                    '' !== $sender->cloakedHost => 'cloak:' . $sender->cloakedHost,
                    '' !== $sender->hostname => 'host:' . $sender->hostname,
                    default => 'uid:' . $sender->uid,
                },
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
            TransferFounderOutcome::TargetNotFound => $context->reply('presentation.error.nick_not_registered', ['%nickname%' => new Fragment((string) $result->targetNickname, Component::Emphasis)], Role::Error),
            TransferFounderOutcome::TargetSuspended => $context->reply('set.founder.suspended', ['%nickname%' => new Fragment((string) $result->targetNickname, Component::Emphasis)], Role::Error),
            TransferFounderOutcome::TargetNotRegistered => $context->reply('set.founder.must_be_registered', ['%nickname%' => new Fragment((string) $result->targetNickname, Component::Emphasis)], Role::Error),
            TransferFounderOutcome::SameFounder => $context->reply('set.founder.cannot_be_self', role: Role::Error),
            TransferFounderOutcome::TargetIsSuccessor => $context->reply('set.founder.cannot_be_successor', role: Role::Error),
            TransferFounderOutcome::ChannelLimitReached => $context->reply('set.founder.limit_exceeded', [
                '%nickname%' => new Fragment((string) $result->targetNickname, Component::Emphasis),
                '%max%' => (string) $result->maximumChannelsPerNick,
            ], Role::Error),
            TransferFounderOutcome::TokenRequired => $context->reply('set.founder.token_required', role: Role::Error),
            TransferFounderOutcome::CredentialUnavailable => $context->reply('set.founder.token_unavailable', role: Role::Error),
            TransferFounderOutcome::Throttled => $context->reply('set.founder.throttled', ['%minutes%' => (string) (int) ceil($result->retryAfterSeconds / 60)], Role::Error),
            TransferFounderOutcome::InvalidToken => $context->reply('set.founder.invalid_token', role: Role::Error),
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
        $context->reply('set.founder.updated', ['%nickname%' => new Fragment((string) $result->targetNickname, Component::Emphasis)], Role::Success);
        $notice = $context->renderReply(Role::Information, 'set.founder.notice_channel', [
            '%from%' => new Fragment($actorNickname, Component::Actor),
            '%nickname%' => new Fragment((string) $result->targetNickname, Component::Emphasis),
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
