<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\RegistrationStatusView;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use DateTimeZone;

final readonly class StatusCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(
        private ShowRegistrationStatusHandler $handler,
        private ?OperatorAuthorizationQuery $authorization = null,
    ) {}

    public function getName(): string
    {
        return 'STATUS';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('status.syntax', [new Argument('channel', prefix: '#')]);
    }

    public function getHelpKey(): string
    {
        return 'status.help';
    }

    public function getExampleKey(): string
    {
        return 'status.example';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'status.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    public function allowsForbiddenChannel(): bool
    {
        return true;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): void
    {
        if (!$this->validateArguments($context)) {
            return;
        }
        $channelName = $context->args[0];
        $status = $this->handler->handle($channelName);
        $context->reply('status.header', ['%channel%' => $channelName]);
        if (null === $status) {
            $context->reply('status.unregistered');
        } elseif (ChannelStatus::Pending === $status->status) {
            $context->reply('status.pending');
        } elseif (ChannelStatus::Rejected === $status->status) {
            $this->presentRejection($context, $channelName, $status);
        } else {
            $key = match ($status->status) {
                ChannelStatus::Active => 'status.registered',
                ChannelStatus::PendingDeletion => 'status.pending_deletion',
                ChannelStatus::Forbidden => 'status.forbidden',
                default => 'status.suspended',
            };
            $context->reply($key);
        }
        $context->reply('status.footer');
    }

    private function presentRejection(ChanServContext $context, string $channelName, RegistrationStatusView $status): void
    {
        $requester = null !== $context->senderAccount && $context->senderAccount->id === $status->requesterAccountId;
        $nickname = $context->getSenderNickname();
        $operator = null !== $this->authorization
            && null !== $nickname
            && $this->authorization->ircOperator(new OperatorActor(
                nickname: $nickname,
                identifiedAccountId: $context->getSenderAccountId(),
                identified: $context->isSenderIdentified(),
                ircOperator: $context->isSenderIrcOperator(),
            ))->granted;
        if (!$requester && !$operator) {
            $context->reply('status.rejected_private', ['%channel%' => $channelName]);

            return;
        }

        $context->reply('status.rejected', ['%channel%' => $channelName]);
        if (null !== $status->rejectionReason) {
            $context->reply('status.rejection_reason', ['%reason%' => $status->rejectionReason]);
        }
        if ($operator && null !== $status->rejectedByNickname && null !== $status->rejectedAt) {
            $context->reply('status.rejection_audit', [
                '%operator%' => $status->rejectedByNickname,
                '%date%' => $context->formatDate($status->rejectedAt),
            ]);
        }
        if ($operator && null !== $status->restrictedUntil) {
            $context->reply('status.restriction_until', [
                '%date%' => $status->restrictedUntil->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y H:i T'),
            ]);
        }
    }
}
