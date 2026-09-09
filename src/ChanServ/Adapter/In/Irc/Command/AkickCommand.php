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
            default => ManageChannelAkickAction::Unknown,
        };
        $item = null;
        $expiresAt = null;
        $reason = null;
        $validRequest = true;
        $now = new DateTimeImmutable();

        if (ManageChannelAkickAction::Add === $action) {
            [$item, $expiresAt, $reason, $validRequest] = $this->parseAddition($context, $now);
        } elseif (ManageChannelAkickAction::Delete === $action) {
            $item = trim($context->args[2] ?? '');
            $validRequest = 3 <= count($context->args) && '' !== $item;
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
        );
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
            ManageChannelAkickOutcome::EntryNotFound => $context->reply('akick.del.not_found', ['%mask%' => $result->mask ?? '']),
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
