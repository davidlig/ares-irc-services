<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannel;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelOutcome;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelResult;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use DateTimeImmutable;

use function array_slice;
use function implode;
use function trim;

/**
 * SAREGISTER <#channel> <nickname> <description> — registers a channel on behalf of a third party.
 *
 * The channel does not need to exist on the network and the operator does not need to join it.
 */
final readonly class SaregisterCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private SaregisterChannelHandlerInterface $handler) {}

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
        return 3;
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
        return 82;
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
        return ChanServPermission::SAREGISTER;
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

    public function execute(ChanServContext $context): CommandOutcome
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }

        $founderNickname = trim($context->args[1] ?? '');
        $description = trim(implode(' ', array_slice($context->args, 2)));

        if ('' === $founderNickname || '' === $description) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new SaregisterChannel(
            channelName: $channelName,
            founderNickname: $founderNickname,
            description: $description,
            actorNickname: $context->getSenderNickname() ?? '',
            occurredAt: new DateTimeImmutable(),
        ));

        $this->present($context, $result);

        if (SaregisterChannelOutcome::Registered !== $result->outcome) {
            return CommandOutcome::rejected();
        }

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->channelName,
            extra: ['founder' => $result->founderNickname],
        ));
    }

    private function present(ChanServContext $context, SaregisterChannelResult $result): void
    {
        match ($result->outcome) {
            SaregisterChannelOutcome::Registered => $context->reply('saregister.registered', [
                'channel' => $result->channelName,
                'nickname' => $result->founderNickname,
            ]),
            SaregisterChannelOutcome::AlreadyRegistered => $context->reply('saregister.already_registered', [
                'channel' => $result->channelName,
            ]),
            SaregisterChannelOutcome::PendingDeletion => $context->reply('saregister.pending_deletion', [
                'channel' => $result->channelName,
            ]),
            SaregisterChannelOutcome::FounderNotFound => $context->reply('saregister.founder_not_found', [
                'nickname' => $result->founderNickname,
            ]),
            SaregisterChannelOutcome::FounderInactive => $context->reply('saregister.founder_inactive', [
                'nickname' => $result->founderNickname,
            ]),
        };
    }
}
