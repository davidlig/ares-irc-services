<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUser;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUserHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;

use function str_contains;

/**
 * UNBAN <#channel> [mask|nickname]. Removes one ban from the channel.
 * Requires UNBAN level. A nickname resolves to the connected user's current mask.
 */
final readonly class UnbanCommand implements ChanServCommandInterface
{
    public function __construct(private UnbanUserHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'UNBAN';
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
        return 'unban.syntax';
    }

    public function getHelpKey(): string
    {
        return 'unban.help';
    }

    public function getOrder(): int
    {
        return 11;
    }

    public function getShortDescKey(): string
    {
        return 'unban.short';
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
        return 'unban.example';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return true;
    }

    public function execute(ChanServContext $context): CommandOutcome
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }
        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('error.not_identified');

            return CommandOutcome::rejected();
        }
        $sender = $context->sender;
        if (null === $sender) {
            $context->reply('error.generic');

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new UnbanUser(
            channelName: $channelName,
            accountId: $account->id,
            founderEquivalent: $context->isLevelFounder,
        ));

        $mask = $this->resolveMask($context, $sender->toUserMask());
        if (null === $mask) {
            return CommandOutcome::rejected();
        }

        $context->getNotifier()->setChannelModes($channelName, '-b', [$mask], $result->channelCreationTimestamp);
        $context->reply('unban.done', ['%mask%' => $mask]);

        return CommandOutcome::success();
    }

    private function resolveMask(ChanServContext $context, string $senderMask): ?string
    {
        $argument = $context->args[1] ?? '';
        if ('' === $argument) {
            return $senderMask;
        }
        if (str_contains($argument, '!') || str_contains($argument, '@')) {
            return $argument;
        }

        $target = $context->getUserLookup()->findByNick($argument);
        if (null === $target) {
            $context->reply('unban.user_not_found', ['%nick%' => $argument]);

            return null;
        }

        return $target->toUserMask();
    }
}
