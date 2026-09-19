<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelOutcome;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\Irc\Application\Port\In\ChannelView;
use DateTimeImmutable;

use function array_slice;
use function implode;
use function in_array;

/** REGISTER <#channel> <description>. */
final readonly class RegisterCommand implements ChanServCommandInterface
{
    private const array REQUIRED_REGISTER_PREFIX_MODES = ['q', 'a', 'o'];

    public function __construct(private RegisterChannelHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'REGISTER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'register.syntax';
    }

    public function getHelpKey(): string
    {
        return 'register.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'register.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return 'IDENTIFIED';
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

        $channelView = $context->getChannelView($channelName);
        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('error.not_identified');

            return;
        }
        $result = $this->handler->handle(new RegisterChannel(
            channelName: $channelName,
            description: implode(' ', array_slice($context->args, 1)),
            accountId: $context->senderAccount?->id,
            actorNickname: $sender->nick,
            actorIdentified: $sender->isIdentified,
            actorIrcOperator: $sender->isOper,
            channelExistsOnNetwork: null !== $channelView,
            hasRequiredChannelRank: null !== $channelView && $this->senderHasRequiredChannelPrefix($channelView, $sender->uid),
            occurredAt: new DateTimeImmutable(),
        ));

        $this->present($context, $channelName, $result);
    }

    private function present(ChanServContext $context, string $channelName, RegisterChannelResult $result): void
    {
        match ($result->outcome) {
            RegisterChannelOutcome::Registered => $context->reply('register.success', ['%channel%' => $channelName]),
            RegisterChannelOutcome::NotIdentified => $context->reply('error.not_identified'),
            RegisterChannelOutcome::PendingDeletion => $context->reply('register.pending_deletion', ['%channel%' => $channelName]),
            RegisterChannelOutcome::ChannelNotOnNetwork => $context->reply('register.channel_not_on_network', ['%channel%' => $channelName]),
            RegisterChannelOutcome::InsufficientChannelRank => $context->reply('register.insufficient_channel_rank', ['%channel%' => $channelName]),
            RegisterChannelOutcome::Throttled => $context->reply('register.throttled', ['minutes' => (string) $result->remainingMinutes]),
            RegisterChannelOutcome::FounderLimitExceeded => $context->reply('register.limit_exceeded', ['%max%' => (string) $result->maximumChannels]),
        };
    }

    private function senderHasRequiredChannelPrefix(ChannelView $channelView, string $senderUid): bool
    {
        foreach ($channelView->members as $member) {
            if ($member['uid'] !== $senderUid) {
                continue;
            }

            foreach ($member['prefixLetters'] ?? [$member['roleLetter']] as $letter) {
                if (in_array($letter, self::REQUIRED_REGISTER_PREFIX_MODES, true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
