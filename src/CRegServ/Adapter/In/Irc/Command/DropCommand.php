<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistration;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

/**
 * DROP <#channel>.
 *
 * Ported from pulsar's do_drop (modules/cregserv/main.c:682-699), which is an
 * is_services_admin command and therefore an IRCop permission here, because ares has no
 * services-admin tier of its own.
 *
 * It removes CReG's record for a channel and, when the channel is already registered with
 * ChanServ, unregisters it there too. A pending or denied request has no ChanServ
 * registration, so only the record is removed — and the channel name is released, which is
 * what makes a denial reversible.
 */
final readonly class DropCommand implements CRegServCommandInterface
{
    public function __construct(private DropChannelRegistrationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'DROP';
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
        return 'drop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'drop.help';
    }

    public function getOrder(): int
    {
        return 7;
    }

    public function getShortDescKey(): string
    {
        return 'drop.short';
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
        return CRegServPermission::DROP;
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $channel = $context->args[0] ?? '';

        $result = $this->handler->handle(new DropChannelRegistration(
            channelName: $channel,
            actorNickname: $context->getSenderNickname() ?? '',
            occurredAt: new DateTimeImmutable(),
        ));

        match ($result->outcome) {
            DropChannelRegistrationOutcome::Dropped => $context->reply('drop.dropped', ['channel' => $result->channelName]),
            DropChannelRegistrationOutcome::DroppedAndUnregistered => $context->reply('drop.dropped_channel', ['channel' => $result->channelName]),
            DropChannelRegistrationOutcome::InvalidChannel => $context->reply('drop.invalid_channel'),
            DropChannelRegistrationOutcome::NoRequest => $context->reply('drop.no_request', ['channel' => $channel]),
        };

        return null;
    }
}
