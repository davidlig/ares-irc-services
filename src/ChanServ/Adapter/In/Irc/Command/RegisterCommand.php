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
use App\Irc\Application\Port\In\Command\AuditableCommandInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function array_slice;
use function implode;
use function in_array;
use function max;

/** REGISTER <#channel> <description>. */
final readonly class RegisterCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;

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

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'register.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('description', rest: true),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'register.help';
    }

    public function getExampleKey(): string
    {
        return 'register.example';
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

    public function execute(ChanServContext $context): CommandOutcome
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }

        $channelView = $context->getChannelView($channelName);
        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error);

            return CommandOutcome::rejected();
        }
        $occurredAt = new DateTimeImmutable();
        $result = $this->handler->handle(new RegisterChannel(
            channelName: $channelName,
            description: implode(' ', array_slice($context->args, 1)),
            accountId: $context->senderAccount?->id,
            actorNickname: $sender->nick,
            actorIdentified: $sender->isIdentified,
            actorIrcOperator: $sender->isOper,
            channelExistsOnNetwork: null !== $channelView,
            hasRequiredChannelRank: null !== $channelView && $this->senderHasRequiredChannelPrefix($channelView, $sender->uid),
            occurredAt: $occurredAt,
        ));

        $this->present($context, $channelName, $result, $occurredAt);

        return match ($result->outcome) {
            RegisterChannelOutcome::Registered => CommandOutcome::success(new IrcopAuditData($channelName, extra: ['outcome' => 'registered'])),
            RegisterChannelOutcome::AwaitingApproval => CommandOutcome::success(new IrcopAuditData($channelName, extra: ['outcome' => 'awaiting_approval'])),
            default => CommandOutcome::rejected(),
        };
    }

    private function present(ChanServContext $context, string $channelName, RegisterChannelResult $result, DateTimeImmutable $occurredAt): void
    {
        match ($result->outcome) {
            RegisterChannelOutcome::Registered => $context->reply('register.success', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Success),
            RegisterChannelOutcome::AwaitingApproval => $context->reply('register.awaiting_approval', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Success),
            RegisterChannelOutcome::RegistrationRestricted => $context->reply(
                null === $result->restrictedUntil ? 'register.restricted_indefinite' : 'register.restricted_until',
                [
                    '%channel%' => new Fragment($channelName, Component::Emphasis),
                    '%date%' => $context->formatDate($result->restrictedUntil),
                    '%remaining_seconds%' => (string) (null === $result->restrictedUntil ? 0 : max(0, $result->restrictedUntil->getTimestamp() - $occurredAt->getTimestamp())),
                ],
                Role::Error,
            ),
            RegisterChannelOutcome::NotIdentified => $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error),
            RegisterChannelOutcome::PendingDeletion => $context->reply('register.pending_deletion', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error),
            RegisterChannelOutcome::ChannelNotOnNetwork => $context->reply('register.channel_not_on_network', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error),
            RegisterChannelOutcome::InsufficientChannelRank => $context->reply('register.insufficient_channel_rank', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error),
            RegisterChannelOutcome::Throttled => $context->reply('register.throttled', ['minutes' => new Fragment((string) $result->remainingMinutes, Component::Emphasis)], Role::Warning),
            RegisterChannelOutcome::FounderLimitExceeded => $context->reply('register.limit_exceeded', ['%max%' => (string) $result->maximumChannels], Role::Error),
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
