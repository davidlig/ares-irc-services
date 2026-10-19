<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUser;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUserHandlerInterface;
use App\ChanServ\Domain\Policy\BanMaskMatcher;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\SenderView;

use function count;
use function str_contains;

/**
 * UNBAN <#channel> [mask|nickname]. Removes every ban in the channel that affects the target.
 * Requires UNBAN level. A literal mask removes exactly that entry.
 */
final readonly class UnbanCommand implements ChanServCommandInterface
{
    public function __construct(
        private UnbanUserHandlerInterface $handler,
        private ChannelLookupPort $channelLookup,
        private BanMaskMatcher $banMaskMatcher,
    ) {}

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

        $argument = $context->args[1] ?? '';
        if (str_contains($argument, '!') || str_contains($argument, '@')) {
            return $this->applyUnban($context, $channelName, [$argument], $argument, $result->channelCreationTimestamp);
        }

        $target = $this->resolveTarget($context, $argument, $sender);
        if (null === $target) {
            return CommandOutcome::rejected();
        }

        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return $this->applyUnban($context, $channelName, [$target->toUserMask()], $target->nick, $result->channelCreationTimestamp);
        }

        $masks = [];
        foreach ($view->bans as $mask) {
            if ($this->banMaskMatcher->matches($mask, $target->nick, $target->ident, $target->hostname)) {
                $masks[] = $mask;
            }
        }

        if ([] === $masks) {
            $context->reply('unban.no_bans', ['%target%' => $target->nick]);

            return CommandOutcome::rejected();
        }

        return $this->applyUnban($context, $channelName, $masks, $target->nick, $result->channelCreationTimestamp);
    }

    private function resolveTarget(ChanServContext $context, string $argument, SenderView $sender): ?SenderView
    {
        if ('' === $argument) {
            return $sender;
        }

        $target = $context->getUserLookup()->findByNick($argument);
        if (null === $target) {
            $context->reply('unban.user_not_found', ['%nick%' => $argument]);

            return null;
        }

        return $target;
    }

    /** @param list<string> $masks */
    private function applyUnban(ChanServContext $context, string $channelName, array $masks, string $target, int $timestamp): CommandOutcome
    {
        foreach ($masks as $mask) {
            $context->getNotifier()->setChannelModes($channelName, '-b', [$mask], $timestamp);
        }
        $context->reply('unban.done', ['%count%' => (string) count($masks), '%target%' => $target]);

        return CommandOutcome::success();
    }
}
