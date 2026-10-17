<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistration;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

/**
 * APROBAR <#channel>.
 *
 * Registers the channel for the user who requested it, writing the UDB C block. The
 * founder stays the requester; the approving oper only moves the request out of the
 * queue, so neither of them has to be in the channel.
 */
final readonly class AprobarCommand implements CRegServCommandInterface
{
    public function __construct(private ApproveChannelRegistrationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'APROBAR';
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
        return 'aprobar.syntax';
    }

    public function getHelpKey(): string
    {
        return 'aprobar.help';
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getShortDescKey(): string
    {
        return 'aprobar.short';
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
        return CRegServPermission::APROBAR;
    }

    public function getExampleKey(): string
    {
        return 'aprobar.example';
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $channel = $context->args[0] ?? '';

        $result = $this->handler->handle(new ApproveChannelRegistration(
            channelName: $channel,
            approverNickname: $context->getSenderNickname() ?? '',
            occurredAt: new DateTimeImmutable(),
        ));

        match ($result->outcome) {
            ApproveChannelRegistrationOutcome::Approved => $context->reply('aprobar.approved', [
                'channel' => $result->channelName,
                'founder' => $result->founderNickname,
            ]),
            ApproveChannelRegistrationOutcome::InvalidChannel => $context->reply('aprobar.invalid_channel'),
            ApproveChannelRegistrationOutcome::NoPendingRequest => $context->reply('aprobar.no_pending', ['channel' => $channel]),
            ApproveChannelRegistrationOutcome::AlreadyRegisteredElsewhere => $context->reply('aprobar.already_registered', ['channel' => $channel]),
            ApproveChannelRegistrationOutcome::AlreadyApproved => $context->reply('aprobar.already_approved', ['channel' => $result->channelName]),
            ApproveChannelRegistrationOutcome::AlreadyDenied => $context->reply('aprobar.already_denied', ['channel' => $result->channelName]),
            ApproveChannelRegistrationOutcome::RegistrationFailed => $context->reply('aprobar.failed', ['channel' => $result->channelName]),
        };

        return null;
    }
}
