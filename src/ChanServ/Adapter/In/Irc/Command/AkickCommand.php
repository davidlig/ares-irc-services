<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ManageAkick\ChannelAkickEntryView;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkick;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickAction;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickOutcome;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickResult;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\Shared\Application\Time\RelativeExpiryParser;
use DateTimeImmutable;

use function array_slice;
use function base64_decode;
use function count;
use function implode;
use function inet_ntop;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * AKICK <#channel> ADD|DEL|LIST [mask] [reason] [expiry].
 *
 * Translates IRC syntax to the typed ChanServ Application boundary and presents its semantic result.
 */
final readonly class AkickCommand implements ChanServCommandInterface
{
    public function __construct(private ManageChannelAkickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'AKICK';
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
        return 'akick.syntax';
    }

    public function getHelpKey(): string
    {
        return 'akick.help';
    }

    public function getOrder(): int
    {
        return 9;
    }

    public function getShortDescKey(): string
    {
        return 'akick.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'akick.add.short', 'help_key' => 'akick.add.help', 'syntax_key' => 'akick.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'akick.del.short', 'help_key' => 'akick.del.help', 'syntax_key' => 'akick.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'akick.list.short', 'help_key' => 'akick.list.help', 'syntax_key' => 'akick.list.syntax'],
            ['name' => 'SET', 'desc_key' => 'akick.set.short', 'help_key' => 'akick.set.help', 'syntax_key' => 'akick.set.syntax', 'example_key' => 'akick.set.example'],
            ['name' => 'CLEAR', 'desc_key' => 'akick.clear.short', 'help_key' => 'akick.clear.help', 'syntax_key' => 'akick.clear.syntax', 'example_key' => 'akick.clear.example'],
            ['name' => 'COUNT', 'desc_key' => 'akick.count.short', 'help_key' => 'akick.count.help', 'syntax_key' => 'akick.count.syntax', 'example_key' => 'akick.count.example'],
            ['name' => 'CHECK', 'desc_key' => 'akick.check.short', 'help_key' => 'akick.check.help', 'syntax_key' => 'akick.check.syntax', 'example_key' => 'akick.check.example'],
            ['name' => 'ENFORCE', 'desc_key' => 'akick.enforce.short', 'help_key' => 'akick.enforce.help', 'syntax_key' => 'akick.enforce.syntax', 'example_key' => 'akick.enforce.example'],
            ['name' => 'VIEW', 'desc_key' => 'akick.view.short', 'help_key' => 'akick.view.help', 'syntax_key' => 'akick.view.syntax', 'example_key' => 'akick.view.example'],
            ['name' => 'LASTUSE', 'desc_key' => 'akick.lastuse.short', 'help_key' => 'akick.lastuse.help', 'syntax_key' => 'akick.lastuse.syntax', 'example_key' => 'akick.lastuse.example'],
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
        $result = $this->handler->handle($this->request($context, $channelName, $subcommand));
        $this->present($context, $channelName, $subcommand, $context->sender?->nick, $result);
    }

    private function request(ChanServContext $context, string $channelName, string $subcommand): ManageChannelAkick
    {
        $action = match ($subcommand) {
            'LIST' => ManageChannelAkickAction::List,
            'ADD' => ManageChannelAkickAction::Add,
            'DEL' => ManageChannelAkickAction::Delete,
            'SET' => ManageChannelAkickAction::Set,
            'CLEAR' => ManageChannelAkickAction::Clear,
            'COUNT' => ManageChannelAkickAction::Count,
            'CHECK' => ManageChannelAkickAction::Check,
            'ENFORCE' => ManageChannelAkickAction::Enforce,
            'VIEW' => ManageChannelAkickAction::View,
            'LASTUSE' => ManageChannelAkickAction::LastUse,
            default => ManageChannelAkickAction::Unknown,
        };
        $item = null;
        $expiresAt = null;
        $reason = null;
        $nickname = null;
        $userMask = null;
        $targetIsOperator = false;
        $validRequest = true;
        $now = new DateTimeImmutable();

        if (ManageChannelAkickAction::Add === $action) {
            [$item, $expiresAt, $reason, $validRequest] = $this->parseAddition($context, $now);
        } elseif (ManageChannelAkickAction::Delete === $action) {
            $item = trim($context->args[2] ?? '');
            $validRequest = 3 <= count($context->args) && '' !== $item;
        } elseif (ManageChannelAkickAction::Set === $action) {
            $item = trim($context->args[2] ?? '');
            $validRequest = 3 <= count($context->args) && '' !== $item;
            $reason = $this->parseReason($context);
        } elseif (ManageChannelAkickAction::Check === $action) {
            $nickname = trim($context->args[2] ?? '');
            $validRequest = 3 <= count($context->args) && '' !== $nickname;
            [$userMask, $targetIsOperator] = $this->resolveConnectedUser($context, $nickname);
        }

        $sender = $context->sender;

        return new ManageChannelAkick(
            channelName: $channelName,
            action: $action,
            actorNickId: $context->senderAccount?->id,
            founderEquivalent: $context->isLevelFounder,
            performedBy: $sender?->nick,
            performedByIp: null === $sender ? '*' : $this->decodeIp($sender->ipBase64),
            performedByHost: null === $sender ? '' : sprintf('%s@%s', $sender->ident, $sender->hostname),
            now: $now,
            item: $item,
            expiresAt: $expiresAt,
            reason: $reason,
            validRequest: $validRequest,
            nickname: $nickname,
            userMask: $userMask,
            targetIsOperator: $targetIsOperator,
        );
    }

    /** Reason given after the entry selector; an empty reason clears the stored one. */
    private function parseReason(ChanServContext $context): ?string
    {
        if (4 > count($context->args)) {
            return null;
        }

        $reason = trim(implode(' ', array_slice($context->args, 3)));

        return '' === $reason ? null : $reason;
    }

    /** @return array{?string, bool} Mask and operator flag of a connected user, null when it is not connected. */
    private function resolveConnectedUser(ChanServContext $context, string $nickname): array
    {
        if ('' === $nickname) {
            return [null, false];
        }

        $user = $context->getUserLookup()->findByNick($nickname);
        if (null === $user) {
            return [null, false];
        }

        return [$user->toUserMask(), $user->isOper];
    }

    /** @return array{?string, ?DateTimeImmutable, ?string, bool} */
    private function parseAddition(ChanServContext $context, DateTimeImmutable $now): array
    {
        $mask = trim($context->args[2] ?? '');
        if (3 > count($context->args) || '' === $mask) {
            return [$mask, null, null, false];
        }
        if (4 > count($context->args)) {
            return [$mask, null, null, true];
        }

        $expiry = trim($context->args[3]);
        if ('' === $expiry) {
            return [$mask, null, null, true];
        }

        $reason = 5 <= count($context->args) ? trim(implode(' ', array_slice($context->args, 4))) : null;
        if ('0' === strtolower($expiry)) {
            return [$mask, null, $reason, true];
        }

        $expiresAt = RelativeExpiryParser::parse($expiry, $now);
        if (null === $expiresAt) {
            return [$mask, null, null, false];
        }

        return [$mask, $expiresAt, '' === $reason ? null : $reason, true];
    }

    private function present(
        ChanServContext $context,
        string $channelName,
        string $subcommand,
        ?string $performedBy,
        ManageChannelAkickResult $result,
    ): void {
        match ($result->outcome) {
            ManageChannelAkickOutcome::ActorNotAuthenticated => $context->reply('error.not_identified'),
            ManageChannelAkickOutcome::MissingSender => null,
            ManageChannelAkickOutcome::InvalidRequest => $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]),
            ManageChannelAkickOutcome::UnknownAction => $context->reply('akick.unknown_sub', ['%sub%' => $subcommand]),
            ManageChannelAkickOutcome::InvalidMask => $context->reply('akick.invalid_mask'),
            ManageChannelAkickOutcome::DangerousMask => $context->reply('akick.dangerous_mask', ['%mask%' => $result->mask ?? '']),
            ManageChannelAkickOutcome::ProtectedUser => $context->reply('akick.protected_user', ['%nickname%' => $result->protectedNickname ?? '']),
            ManageChannelAkickOutcome::DuplicateActive => $context->reply('akick.add.already_exists', ['%mask%' => $result->mask ?? '']),
            ManageChannelAkickOutcome::LimitReached => $context->reply('akick.max_entries', ['%max%' => (string) AkickAdditionPolicy::MAX_ENTRIES]),
            ManageChannelAkickOutcome::ListEmpty => $context->reply('akick.list.empty', ['%channel%' => $channelName]),
            ManageChannelAkickOutcome::Listed => $this->presentList($context, $channelName, $result->entries),
            ManageChannelAkickOutcome::Added => $this->presentAdded($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::Deleted => $this->presentDeleted($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::EntryNotFound => $context->reply(
                'SET' === $subcommand ? 'akick.set.not_found' : 'akick.del.not_found',
                ['%mask%' => $result->mask ?? ''],
            ),
            ManageChannelAkickOutcome::InvalidReason => $context->reply('akick.set.invalid_reason', [
                '%max%' => (string) ChannelAkick::MAX_REASON_LENGTH,
            ]),
            ManageChannelAkickOutcome::ReasonUpdated => $this->presentReasonUpdated($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::Cleared => $this->presentCleared($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::Counted => $context->reply('akick.count.result', [
                '%channel%' => $channelName,
                '%count%' => (string) $result->count,
            ]),
            ManageChannelAkickOutcome::Checked => $this->presentChecked($context, $channelName, $result),
            ManageChannelAkickOutcome::NicknameNotConnected => $context->reply('akick.check.not_connected', [
                '%nickname%' => $result->nickname ?? '',
            ]),
            ManageChannelAkickOutcome::Enforced => $context->reply('akick.enforce.done', [
                '%channel%' => $channelName,
                '%count%' => (string) $result->count,
            ]),
            ManageChannelAkickOutcome::ViewEmpty => $context->reply('akick.view.empty', ['%channel%' => $channelName]),
            ManageChannelAkickOutcome::Viewed => $this->presentView($context, $channelName, $result->entries),
            ManageChannelAkickOutcome::LastUseEmpty => $context->reply('akick.lastuse.empty', ['%channel%' => $channelName]),
            ManageChannelAkickOutcome::LastUsed => $this->presentLastUse($context, $channelName, $result->entries),
        };
    }

    /** @param list<ChannelAkickEntryView> $entries */
    private function presentList(ChanServContext $context, string $channelName, array $entries): void
    {
        $context->reply('akick.list.header', ['%channel%' => $channelName]);
        foreach ($entries as $index => $entry) {
            $context->reply('akick.list.entry', [
                '%index%' => (string) ($index + 1),
                '%mask%' => sprintf("\x0304%s\x03", $entry->mask),
                '%reason%' => $entry->reason ?? $context->trans('akick.list.no_reason'),
                '%nickname%' => $entry->creatorNickname ?? $context->trans('akick.list.unknown_creator'),
                '%expiration%' => null === $entry->expiresAt
                    ? $context->trans('akick.list.never_expires')
                    : $context->formatDate($entry->expiresAt),
            ]);
        }
    }

    private function presentAdded(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $mask = $result->mask ?? '';
        $context->reply('akick.add.done', ['%mask%' => $mask]);
        $notice = $context->trans('akick.add.notice_channel', [
            '%from%' => $performedBy ?? '',
            '%mask%' => $mask,
            '%reason%' => null === $result->reason ? $context->trans('akick.list.no_reason') : $result->reason,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentDeleted(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $mask = $result->mask ?? '';
        $context->reply('akick.del.done', ['%mask%' => $mask]);
        $notice = $context->trans('akick.del.notice_channel', [
            '%from%' => $performedBy ?? '',
            '%mask%' => $mask,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentReasonUpdated(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $mask = $result->mask ?? '';
        $context->reply('akick.set.done', ['%mask%' => $mask]);
        $notice = $context->trans('akick.set.notice_channel', [
            '%from%' => $performedBy ?? '',
            '%mask%' => $mask,
            '%reason%' => null === $result->reason ? $context->trans('akick.list.no_reason') : $result->reason,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentCleared(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $count = (string) $result->count;
        $context->reply('akick.clear.done', ['%channel%' => $channelName, '%count%' => $count]);
        $notice = $context->trans('akick.clear.notice_channel', [
            '%from%' => $performedBy ?? '',
            '%count%' => $count,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentChecked(ChanServContext $context, string $channelName, ManageChannelAkickResult $result): void
    {
        $nickname = $result->nickname ?? '';
        if ([] === $result->entries) {
            $context->reply('akick.check.empty', ['%channel%' => $channelName, '%nickname%' => $nickname]);

            return;
        }

        $context->reply('akick.check.header', ['%channel%' => $channelName, '%nickname%' => $nickname]);
        foreach ($result->entries as $index => $entry) {
            $context->reply('akick.list.entry', [
                '%index%' => (string) ($index + 1),
                '%mask%' => sprintf("\x0304%s\x03", $entry->mask),
                '%reason%' => $entry->reason ?? $context->trans('akick.list.no_reason'),
                '%nickname%' => $entry->creatorNickname ?? $context->trans('akick.list.unknown_creator'),
                '%expiration%' => null === $entry->expiresAt
                    ? $context->trans('akick.list.never_expires')
                    : $context->formatDate($entry->expiresAt),
            ]);
        }
    }

    /** @param list<ChannelAkickEntryView> $entries */
    private function presentView(ChanServContext $context, string $channelName, array $entries): void
    {
        $context->reply('akick.view.header', ['%channel%' => $channelName]);
        foreach ($entries as $index => $entry) {
            $context->reply('akick.view.entry', $this->detailParams($context, $entry, $index, 'akick.view.never_used'));
        }
    }

    /** @param list<ChannelAkickEntryView> $entries */
    private function presentLastUse(ChanServContext $context, string $channelName, array $entries): void
    {
        $context->reply('akick.lastuse.header', ['%channel%' => $channelName]);
        foreach ($entries as $index => $entry) {
            $context->reply('akick.lastuse.entry', $this->detailParams($context, $entry, $index, 'akick.lastuse.never_used'));
        }
    }

    /**
     * Placeholders of an inspected entry: the mask, the reason, the author, the creation
     * date, the expiry, the use counter and the last time the entry was applied.
     *
     * @return array<string, string>
     */
    private function detailParams(ChanServContext $context, ChannelAkickEntryView $entry, int $index, string $neverUsedKey): array
    {
        return [
            '%index%' => (string) ($index + 1),
            '%mask%' => sprintf("\x0304%s\x03", $entry->mask),
            '%reason%' => $entry->reason ?? $context->trans('akick.list.no_reason'),
            '%nickname%' => $entry->creatorNickname ?? $context->trans('akick.list.unknown_creator'),
            '%created%' => null === $entry->createdAt ? '' : $context->formatDate($entry->createdAt),
            '%expiration%' => null === $entry->expiresAt
                ? $context->trans('akick.list.never_expires')
                : $context->formatDate($entry->expiresAt),
            '%uses%' => (string) $entry->uses,
            '%lastuse%' => null === $entry->lastUsedAt
                ? $context->trans($neverUsedKey)
                : $context->formatDate($entry->lastUsedAt),
        ];
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

        return false === $ip ? $ipBase64 : $ip;
    }
}
