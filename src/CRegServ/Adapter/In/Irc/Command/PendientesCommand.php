<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrations;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrationsHandlerInterface;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Irc\Application\Port\In\Command\CommandOutcome;

/**
 * PENDIENTES.
 *
 * The oper work queue, ported from pulsar's do_pendientes (modules/cregserv/main.c:534-553):
 * one line per request waiting for review, carrying the channel, who asked, when, and the
 * description they wrote, closed by an end marker or an explicit empty notice.
 */
final readonly class PendientesCommand implements CRegServCommandInterface
{
    public function __construct(private ListChannelRegistrationsHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'PENDIENTES';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'pendientes.syntax';
    }

    public function getHelpKey(): string
    {
        return 'pendientes.help';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'pendientes.short';
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
        return CRegServPermission::PENDIENTES;
    }

    public function getExampleKey(): string
    {
        return 'pendientes.example';
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $result = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Pending));

        if ([] === $result->rows) {
            $context->reply('pendientes.empty');

            return null;
        }

        foreach ($result->rows as $row) {
            $context->reply('pendientes.line', [
                'channel' => $row->channel,
                'requester' => $row->requesterNick,
                'date' => $context->formatDate($row->createdAt),
                'description' => $row->description,
            ]);
        }

        $context->reply('pendientes.end');

        return null;
    }
}
