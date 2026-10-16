<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistration;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

/**
 * CANCEL <#channel>.
 *
 * Lets a user withdraw their own registration request while it is still waiting for an
 * oper, freeing the channel name without operator intervention.
 */
final readonly class CancelChannelRegistrationCommand implements CRegServCommandInterface
{
    public function __construct(private CancelChannelRegistrationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'CANCEL';
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
        return 'cancel.syntax';
    }

    public function getHelpKey(): string
    {
        return 'cancel.help';
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getShortDescKey(): string
    {
        return 'cancel.short';
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
        return 'cancel.example';
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $senderAccount = $context->getSenderAccount();
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        $channel = $context->args[0] ?? '';

        $result = $this->handler->handle(new CancelChannelRegistration(
            channelName: $channel,
            requesterAccountId: $senderAccount->id,
            occurredAt: new DateTimeImmutable(),
        ));

        match ($result->outcome) {
            CancelChannelRegistrationOutcome::Cancelled => $context->reply('cancel.cancelled', ['channel' => $channel]),
            CancelChannelRegistrationOutcome::InvalidChannel => $context->reply('cancel.invalid_channel'),
            CancelChannelRegistrationOutcome::NoRequest => $context->reply('cancel.no_request', ['channel' => $channel]),
            CancelChannelRegistrationOutcome::NotOwner => $context->reply('cancel.not_yours', ['channel' => $channel]),
            CancelChannelRegistrationOutcome::NotPending => $context->reply('cancel.not_pending', ['channel' => $channel]),
        };

        return null;
    }
}
