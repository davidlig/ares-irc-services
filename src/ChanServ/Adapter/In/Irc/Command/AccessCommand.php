<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccess;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessAction;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessHandlerInterface;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessOutcome;
use App\ChanServ\Application\UseCase\ManageAccess\ManageChannelAccessResult;
use App\ChanServ\Domain\Entity\ChannelAccess;

use function sprintf;
use function strtoupper;

/** ACCESS <#channel> ADD|DEL|LIST [nickname] [level]. */
final readonly class AccessCommand implements ChanServCommandInterface
{
    public function __construct(private ManageChannelAccessHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'ACCESS';
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
        return 'access.syntax';
    }

    public function getHelpKey(): string
    {
        return 'access.help';
    }

    public function getOrder(): int
    {
        return 8;
    }

    public function getShortDescKey(): string
    {
        return 'access.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'LIST', 'desc_key' => 'access.list.short', 'help_key' => 'access.list.help', 'syntax_key' => 'access.list.syntax'],
            ['name' => 'ADD', 'desc_key' => 'access.add.short', 'help_key' => 'access.add.help', 'syntax_key' => 'access.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'access.del.short', 'help_key' => 'access.del.help', 'syntax_key' => 'access.del.syntax'],
        ];
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
        return true;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $subcommand = strtoupper($context->args[1] ?? '');
        $action = match ($subcommand) {
            'LIST' => ManageChannelAccessAction::List,
            'ADD' => ManageChannelAccessAction::Add,
            'DEL' => ManageChannelAccessAction::Delete,
            default => ManageChannelAccessAction::Unknown,
        };

        $sender = $context->sender;
        $account = $context->senderAccount;
        $authenticatedActorId = null !== $sender && null !== $account ? $account->id : null;
        $targetNickname = trim($context->args[2] ?? '');
        $levelArgument = trim($context->args[3] ?? '');
        $performedBy = null === $sender ? '' : $sender->nick;
        $result = $this->handler->handle(new ManageChannelAccess(
            channelName: $channelName,
            action: $action,
            actorNickId: $authenticatedActorId,
            founderEquivalent: $context->isLevelFounder,
            performedBy: $performedBy,
            performedByIp: null === $sender ? '*' : $this->decodeIp($sender->ipBase64),
            performedByHost: null === $sender ? '' : sprintf('%s@%s', $sender->ident, $sender->hostname),
            targetNickname: '' === $targetNickname ? null : $targetNickname,
            level: '' === $levelArgument ? null : (int) $levelArgument,
        ));

        $this->present($context, $channelName, $subcommand, $performedBy, $result);
    }

    private function present(ChanServContext $context, string $channelName, string $subcommand, string $performedBy, ManageChannelAccessResult $result): void
    {
        match ($result->outcome) {
            ManageChannelAccessOutcome::ActorNotAuthenticated => $context->reply('error.not_identified'),
            ManageChannelAccessOutcome::UnknownAction => $context->reply('access.unknown_sub', ['%sub%' => $subcommand]),
            ManageChannelAccessOutcome::InvalidRequest => $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]),
            ManageChannelAccessOutcome::InvalidLevel => $context->reply('access.level_range', [
                '%min%' => (string) ChannelAccess::LEVEL_MIN,
                '%max%' => (string) ChannelAccess::LEVEL_MAX,
            ]),
            ManageChannelAccessOutcome::ListEmpty => $context->reply('access.list.empty', ['%channel%' => $channelName]),
            ManageChannelAccessOutcome::Listed => $this->presentList($context, $channelName, $result),
            ManageChannelAccessOutcome::Added => $this->presentAdded($context, $channelName, $performedBy, $result),
            ManageChannelAccessOutcome::Deleted => $this->presentDeleted($context, $channelName, $performedBy, $result),
            ManageChannelAccessOutcome::TargetNotRegistered => $context->reply('error.nick_not_registered', ['%nickname%' => (string) $result->targetNickname]),
            ManageChannelAccessOutcome::FounderNotAllowed => $context->reply('access.founder_not_in_list'),
            ManageChannelAccessOutcome::LimitReached => $context->reply('access.max_entries', ['%max%' => (string) ChannelAccess::MAX_ENTRIES_PER_CHANNEL]),
            ManageChannelAccessOutcome::CannotManageLevel => $context->reply('access.cannot_manage_level'),
            ManageChannelAccessOutcome::EntryNotFound => $context->reply('access.del.not_in_list', ['%nickname%' => (string) $result->targetNickname]),
        };
    }

    private function presentList(ChanServContext $context, string $channelName, ManageChannelAccessResult $result): void
    {
        $context->reply('access.list.header', ['%channel%' => $channelName]);
        foreach ($result->entries as $index => $entry) {
            $context->reply('access.list.entry', [
                '%index%' => (string) ($index + 1),
                '%nickname%' => $entry->nickname,
                '%level%' => (string) $entry->level,
            ]);
        }
    }

    private function presentAdded(ChanServContext $context, string $channelName, string $performedBy, ManageChannelAccessResult $result): void
    {
        $nickname = (string) $result->targetNickname;
        $level = (string) $result->level;
        $context->reply('access.add.done', ['%nickname%' => $nickname, '%level%' => $level]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $context->trans('access.add.notice_channel', [
            '%from%' => $performedBy,
            '%to%' => $nickname,
            '%level%' => $level,
        ]));
    }

    private function presentDeleted(ChanServContext $context, string $channelName, string $performedBy, ManageChannelAccessResult $result): void
    {
        $nickname = (string) $result->targetNickname;
        $context->reply('access.del.done', ['%nickname%' => $nickname]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $context->trans('access.del.notice_channel', [
            '%from%' => $performedBy,
            '%to%' => $nickname,
        ]));
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
