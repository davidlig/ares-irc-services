<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelOutcome;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use DateTimeImmutable;

use function array_slice;
use function implode;

/**
 * SAREGISTER <#channel> [description]; IRC parsing and presentation only.
 *
 * Registers a channel at staff request without requiring the operator to be in
 * it, and without requiring the channel to exist on the network yet. The legacy
 * Anope command of the same name.
 */
final readonly class SaregisterCommand implements ChanServCommandInterface
{
    public function __construct(private RegisterChannelHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SAREGISTER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'saregister.syntax';
    }

    public function getHelpKey(): string
    {
        return 'saregister.help';
    }

    public function getOrder(): int
    {
        return 81;
    }

    public function getShortDescKey(): string
    {
        return 'saregister.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return ChanServPermission::SAREGISTER;
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('error.not_identified');

            return;
        }

        $channelView = $context->getChannelView($channelName);
        $result = $this->handler->handle(new RegisterChannel(
            channelName: $channelName,
            description: implode(' ', array_slice($context->args, 1)),
            accountId: $context->senderAccount?->id,
            actorNickname: $sender->nick,
            actorIdentified: $sender->isIdentified,
            actorIrcOperator: $sender->isOper,
            channelExistsOnNetwork: null !== $channelView,
            hasRequiredChannelRank: true,
            occurredAt: new DateTimeImmutable(),
            force: true,
        ));

        $this->present($context, $channelName, $result);
    }

    private function present(ChanServContext $context, string $channelName, RegisterChannelResult $result): void
    {
        match ($result->outcome) {
            RegisterChannelOutcome::Registered => $context->reply('saregister.done', ['%channel%' => $channelName]),
            RegisterChannelOutcome::NotIdentified => $context->reply('error.not_identified'),
            RegisterChannelOutcome::PendingDeletion => $context->reply('register.pending_deletion', ['%channel%' => $channelName]),
            RegisterChannelOutcome::ChannelNotOnNetwork => $context->reply('register.channel_not_on_network', ['%channel%' => $channelName]),
            RegisterChannelOutcome::InsufficientChannelRank => $context->reply('register.insufficient_channel_rank', ['%channel%' => $channelName]),
            RegisterChannelOutcome::Throttled => $context->reply('register.throttled', ['%minutes%' => (string) $result->remainingMinutes]),
            RegisterChannelOutcome::FounderLimitExceeded => $context->reply('register.limit_exceeded', ['%max%' => (string) $result->maximumChannels]),
        };
    }
}
