<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistration;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

use function array_slice;
use function implode;

/**
 * REGISTRA <#channel> <description>.
 *
 * Queues a registration request for a services oper to evaluate. The channel is only
 * registered with ChanServ once a request is approved, so nothing reaches UDB here.
 */
final readonly class RegistraCommand implements CRegServCommandInterface
{
    public function __construct(private RequestChannelRegistrationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'REGISTRA';
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
        return 'registra.syntax';
    }

    public function getHelpKey(): string
    {
        return 'registra.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'registra.short';
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

    public function getExampleKey(): string
    {
        return 'registra.example';
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $senderAccount = $context->getSenderAccount();
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        $channel = $context->args[0] ?? '';

        $result = $this->handler->handle(new RequestChannelRegistration(
            channelName: $channel,
            description: implode(' ', array_slice($context->args, 1)),
            requesterAccountId: $senderAccount->id,
            requesterNickname: $senderAccount->nickname,
            actorIrcOperator: $context->isSenderIrcOperator(),
            occurredAt: new DateTimeImmutable(),
        ));

        match ($result->outcome) {
            RequestChannelRegistrationOutcome::Requested => $context->reply('registra.requested', ['channel' => $channel]),
            RequestChannelRegistrationOutcome::InvalidChannel => $context->reply('registra.invalid_channel'),
            RequestChannelRegistrationOutcome::ChannelTooLong => $context->reply('registra.channel_too_long'),
            RequestChannelRegistrationOutcome::DescriptionTooLong => $context->reply('registra.description_too_long'),
            RequestChannelRegistrationOutcome::AlreadyRegistered => $context->reply('registra.already_registered', ['channel' => $channel]),
            RequestChannelRegistrationOutcome::PendingDeletion => $context->reply('registra.pending_deletion', ['channel' => $channel]),
            RequestChannelRegistrationOutcome::DuplicateRequest => $context->reply('registra.duplicate', ['channel' => $channel]),
            RequestChannelRegistrationOutcome::RequestDenied => $context->reply('registra.denied', ['channel' => $channel]),
            RequestChannelRegistrationOutcome::Throttled => $context->reply('registra.throttled', ['minutes' => $result->remainingMinutes ?? 0]),
        };

        return null;
    }
}
