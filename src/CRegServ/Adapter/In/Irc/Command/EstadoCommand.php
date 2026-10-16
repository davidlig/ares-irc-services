<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatus;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusHandlerInterface;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;

/**
 * ESTADO <#channel>.
 *
 * Reports where a channel name stands: already registered with ChanServ, queued and waiting
 * for an oper, denied, or with no registration process at all.
 */
final readonly class EstadoCommand implements CRegServCommandInterface
{
    public function __construct(private GetChannelRegistrationStatusHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'ESTADO';
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
        return 'estado.syntax';
    }

    public function getHelpKey(): string
    {
        return 'estado.help';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return 'estado.short';
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

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $senderAccount = $context->getSenderAccount();
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        $channel = $context->args[0] ?? '';

        $result = $this->handler->handle(new GetChannelRegistrationStatus($channel));

        match ($result->outcome) {
            GetChannelRegistrationStatusOutcome::Registered => $context->reply('estado.registered', ['channel' => $channel]),
            GetChannelRegistrationStatusOutcome::Pending => $context->reply('estado.pending', [
                'channel' => $channel,
                'date' => $context->formatDate($result->requestedAt),
            ]),
            GetChannelRegistrationStatusOutcome::Denied => $context->reply('estado.denied', [
                'channel' => $channel,
                'date' => $context->formatDate($result->deniedAt),
                'reason' => $result->deniedReason ?? '',
            ]),
            GetChannelRegistrationStatusOutcome::NoRequest => $context->reply('estado.no_request', ['channel' => $channel]),
        };

        return null;
    }
}
