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
 * DENEGADOS.
 *
 * Ported from pulsar's do_denegados (modules/cregserv/main.c:556-575): one line per denied
 * request, showing the channel and the reason the oper gave, which is the whole point of
 * making that reason mandatory in DENEGAR.
 */
final readonly class DenegadosCommand implements CRegServCommandInterface
{
    public function __construct(private ListChannelRegistrationsHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'DENEGADOS';
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
        return 'denegados.syntax';
    }

    public function getHelpKey(): string
    {
        return 'denegados.help';
    }

    public function getOrder(): int
    {
        return 6;
    }

    public function getShortDescKey(): string
    {
        return 'denegados.short';
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
        return CRegServPermission::DENEGADOS;
    }

    public function getExampleKey(): string
    {
        return 'denegados.example';
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $result = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Denied));

        if ([] === $result->rows) {
            $context->reply('denegados.empty');

            return null;
        }

        foreach ($result->rows as $row) {
            $context->reply('denegados.line', [
                'channel' => $row->channel,
                'reason' => $row->deniedReason ?? '',
            ]);
        }

        $context->reply('denegados.end');

        return null;
    }
}
