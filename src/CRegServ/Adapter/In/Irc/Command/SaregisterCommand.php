<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\Security\CRegServPermission;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

use function array_slice;
use function implode;
use function str_starts_with;

/**
 * SAREGISTER <#channel> [description]; IRC parsing and presentation only.
 *
 * The legacy ChanServ command, ported to CReG. It registers a channel at staff request
 * without requiring the operator to be in it and without the channel existing on the
 * network yet; the request-side commands (REGISTRA and its approval queue) live here
 * too, so both paths belong to the same service.
 *
 * The force semantics are unchanged: the registration goes through ChanServ's
 * public ChannelRegistration entry point, which runs forced and privileged and leaves
 * the UDB C block to the sync subscriber.
 */
final readonly class SaregisterCommand implements CRegServCommandInterface
{
    public function __construct(private ChannelRegistration $registration) {}

    public function getName(): string
    {
        return 'SAREGISTER';
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
        return 'saregister.syntax';
    }

    public function getHelpKey(): string
    {
        return 'saregister.help';
    }

    public function getOrder(): int
    {
        return 8;
    }

    public function getShortDescKey(): string
    {
        return 'saregister.short';
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
        return CRegServPermission::SAREGISTER;
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $channelName = $context->args[0] ?? '';
        if (!str_starts_with($channelName, '#')) {
            $context->reply('saregister.invalid_channel');

            return null;
        }

        $accountId = $context->getSenderAccountId();
        if (null === $accountId) {
            $context->reply('error.not_identified');

            return null;
        }

        $outcome = $this->registration->registerApproved(
            $channelName,
            implode(' ', array_slice($context->args, 1)),
            $accountId,
            $context->getSenderNickname() ?? '',
            new DateTimeImmutable(),
        );

        match ($outcome) {
            ChannelRegistrationOutcome::Registered => $context->reply('saregister.done', ['%channel%' => $channelName]),
            ChannelRegistrationOutcome::PendingDeletion => $context->reply('saregister.pending_deletion', ['%channel%' => $channelName]),
            ChannelRegistrationOutcome::AlreadyRegistered => $context->reply('saregister.already_registered', ['%channel%' => $channelName]),
            ChannelRegistrationOutcome::Failed => $context->reply('saregister.failed', ['%channel%' => $channelName]),
        };

        return null;
    }
}
