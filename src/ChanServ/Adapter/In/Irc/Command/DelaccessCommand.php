<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\RemoveOwnAccess\RemoveOwnChannelAccess;
use App\ChanServ\Application\UseCase\RemoveOwnAccess\RemoveOwnChannelAccessHandlerInterface;
use App\ChanServ\Application\UseCase\RemoveOwnAccess\RemoveOwnChannelAccessOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use DateTimeImmutable;

use function base64_decode;
use function inet_ntop;
use function sprintf;

final readonly class DelaccessCommand implements ChanServCommandInterface
{
    public function __construct(private RemoveOwnChannelAccessHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'DELACCESS';
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
        return 'delaccess.syntax';
    }

    public function getHelpKey(): string
    {
        return 'delaccess.help';
    }

    public function getOrder(): int
    {
        return 9;
    }

    public function getShortDescKey(): string
    {
        return 'delaccess.short';
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

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    public function allowsForbiddenChannel(): bool
    {
        return false;
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
        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('error.not_identified');

            return CommandOutcome::rejected();
        }
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $outcome = $this->handler->handle(new RemoveOwnChannelAccess(
            channelName: $channelName,
            accountId: $account->id,
            nickname: $sender->nick,
            actorIp: $this->decodeIp($sender->ipBase64),
            actorHost: sprintf('%s@%s', $sender->ident, $sender->hostname),
            occurredAt: new DateTimeImmutable(),
            founderEquivalent: $context->isLevelFounder,
        ));
        if (RemoveOwnChannelAccessOutcome::NotRegistered === $outcome) {
            $context->reply('error.channel_not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (RemoveOwnChannelAccessOutcome::FounderNotInAccess === $outcome) {
            $context->reply('delaccess.founder_not_in_access', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (RemoveOwnChannelAccessOutcome::NotInAccess === $outcome) {
            $context->reply('delaccess.not_in_list', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $context->reply('delaccess.done', ['%channel%' => $channelName]);
        $notice = $context->trans('delaccess.notice_channel', ['%nickname%' => $sender->nick]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);

        return CommandOutcome::success();
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }
        $binary = base64_decode($ipBase64, true);
        if (false === $binary) {
            return $ipBase64;
        }
        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
