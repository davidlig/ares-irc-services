<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistration;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistrationOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

use function array_slice;
use function implode;

/**
 * DENEGAR <#channel> <reason>.
 *
 * Refuses a registration request and keeps the channel name free. The reason is
 * mandatory, as in pulsar's do_denegar, and is shown later by DENEGADOS.
 */
final readonly class DenegarCommand implements CRegServCommandInterface
{
    public function __construct(private DenyChannelRegistrationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'DENEGAR';
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
        return 'denegar.syntax';
    }

    public function getHelpKey(): string
    {
        return 'denegar.help';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'denegar.short';
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
        return CRegServPermission::DENEGAR;
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $channel = $context->args[0] ?? '';

        $result = $this->handler->handle(new DenyChannelRegistration(
            channelName: $channel,
            reason: implode(' ', array_slice($context->args, 1)),
            denierNickname: $context->getSenderNickname() ?? '',
            occurredAt: new DateTimeImmutable(),
        ));

        match ($result->outcome) {
            DenyChannelRegistrationOutcome::Denied => $context->reply('denegar.denied', ['channel' => $result->channelName]),
            DenyChannelRegistrationOutcome::InvalidChannel => $context->reply('denegar.invalid_channel'),
            DenyChannelRegistrationOutcome::NoPendingRequest => $context->reply('denegar.no_pending', ['channel' => $channel]),
            DenyChannelRegistrationOutcome::AlreadyApproved => $context->reply('denegar.already_approved', ['channel' => $result->channelName]),
            DenyChannelRegistrationOutcome::AlreadyDenied => $context->reply('denegar.already_denied', ['channel' => $result->channelName]),
        };

        return null;
    }
}
