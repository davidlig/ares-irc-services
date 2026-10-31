<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServDetailPresenter;
use App\ChanServ\Application\UseCase\ManageAkick\ChannelAkickEntryView;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkick;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickAction;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickOutcome;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickResult;
use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
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
    use ChanServArgumentDefinitionSupport;

    public function __construct(private ManageChannelAkickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'AKICK';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'akick.syntax',
            arguments: [new Argument('channel'), new Argument('option')],
            variants: [
                'ADD' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.add.syntax',
                    arguments: [
                        new Argument('mask', nonBlank: true),
                        new Argument('expiry', optional: true),
                        new Argument('reason', optional: true, rest: true),
                    ],
                ),
                'DEL' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.del.syntax',
                    arguments: [
                        new Argument('mask', nonBlank: true),
                    ],
                ),
                'LIST' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.list.syntax',
                ),
                'VIEW' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.view.syntax',
                    arguments: [
                        new Argument('mask', nonBlank: true),
                    ],
                ),
                'CLEAR' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.clear.syntax',
                    allowExtra: false,
                ),
                'CHECK' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.check.syntax',
                    arguments: [
                        new Argument('nickname', nonBlank: true),
                    ],
                ),
                'ENFORCE' => new CommandArgumentDefinition(
                    syntaxKey: 'akick.enforce.syntax',
                    allowExtra: false,
                ),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'akick.help';
    }

    public function getExampleKey(): string
    {
        return 'akick.example';
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
            ['name' => 'ADD', 'desc_key' => 'akick.add.short', 'help_key' => 'akick.add.help', 'example_key' => 'akick.add.example', 'syntax_key' => 'akick.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'akick.del.short', 'help_key' => 'akick.del.help', 'example_key' => 'akick.del.example', 'syntax_key' => 'akick.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'akick.list.short', 'help_key' => 'akick.list.help', 'example_key' => 'akick.list.example', 'syntax_key' => 'akick.list.syntax'],
            ['name' => 'VIEW', 'desc_key' => 'akick.view.short', 'help_key' => 'akick.view.help', 'example_key' => 'akick.view.example', 'syntax_key' => 'akick.view.syntax'],
            ['name' => 'CLEAR', 'desc_key' => 'akick.clear.short', 'help_key' => 'akick.clear.help', 'example_key' => 'akick.clear.example', 'syntax_key' => 'akick.clear.syntax'],
            ['name' => 'CHECK', 'desc_key' => 'akick.check.short', 'help_key' => 'akick.check.help', 'example_key' => 'akick.check.example', 'syntax_key' => 'akick.check.syntax'],
            ['name' => 'ENFORCE', 'desc_key' => 'akick.enforce.short', 'help_key' => 'akick.enforce.help', 'example_key' => 'akick.enforce.example', 'syntax_key' => 'akick.enforce.syntax'],
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
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return;
        }

        if (!$this->validateArguments($context)) {
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
            'CLEAR' => ManageChannelAkickAction::Clear,
            'VIEW' => ManageChannelAkickAction::View,
            'CHECK' => ManageChannelAkickAction::Check,
            'ENFORCE' => ManageChannelAkickAction::Enforce,
            default => ManageChannelAkickAction::Unknown,
        };
        $item = null;
        $expiresAt = null;
        $reason = null;
        $validRequest = true;
        $now = new DateTimeImmutable();

        if (ManageChannelAkickAction::Add === $action) {
            [$item, $expiresAt, $reason, $validRequest] = $this->parseAddition($context, $now);
        } elseif (ManageChannelAkickAction::Delete === $action || ManageChannelAkickAction::View === $action || ManageChannelAkickAction::Check === $action) {
            $item = trim($context->args[2] ?? '');
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
            ManageChannelAkickOutcome::ActorNotAuthenticated => $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error),
            ManageChannelAkickOutcome::MissingSender => null,
            ManageChannelAkickOutcome::InvalidRequest => $context->reply('presentation.error.syntax', ['syntax' => new Fragment($context->trans($this->getSyntaxKey()), Component::Command)], Role::Error),
            ManageChannelAkickOutcome::UnknownAction => $context->reply('akick.unknown_sub', ['%sub%' => new Fragment($subcommand, Component::Emphasis)], Role::Error),
            ManageChannelAkickOutcome::InvalidMask => $context->reply('akick.invalid_mask', role: Role::Error),
            ManageChannelAkickOutcome::DangerousMask => $context->reply('akick.dangerous_mask', ['%mask%' => new Fragment($result->mask ?? '', Component::Emphasis)], Role::Error),
            ManageChannelAkickOutcome::ProtectedUser => $context->reply('akick.protected_user', ['%nickname%' => new Fragment($result->protectedNickname ?? '', Component::Emphasis)], Role::Error),
            ManageChannelAkickOutcome::DuplicateActive => $context->reply('akick.add.already_exists', ['%mask%' => new Fragment($result->mask ?? '', Component::Emphasis)], Role::Error),
            ManageChannelAkickOutcome::LimitReached => $context->reply('akick.max_entries', ['%max%' => (string) AkickAdditionPolicy::MAX_ENTRIES], Role::Error),
            ManageChannelAkickOutcome::ListEmpty => $context->reply('akick.list.empty', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Information),
            ManageChannelAkickOutcome::Listed => $this->presentList($context, $channelName, $result->entries),
            ManageChannelAkickOutcome::Added => $this->presentAdded($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::Deleted => $this->presentDeleted($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::EntryNotFound => $context->reply('akick.del.not_found', ['%mask%' => new Fragment($result->mask ?? '', Component::Emphasis)], Role::Error),
            ManageChannelAkickOutcome::ClearEmpty => $context->reply('akick.clear.empty', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Information),
            ManageChannelAkickOutcome::Cleared => $this->presentCleared($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::Viewed => $this->presentViewed($context, $channelName, $result),
            ManageChannelAkickOutcome::Checked => $this->presentChecked($context, $channelName, $result),
            ManageChannelAkickOutcome::CheckNoMatch => $context->reply('akick.check.no_match', [
                '%nickname%' => new Fragment($result->targetNickname ?? '', Component::Emphasis),
                '%channel%' => new Fragment($channelName, Component::Emphasis),
            ], Role::Information),
            ManageChannelAkickOutcome::CheckUserNotConnected => $context->reply('akick.check.not_connected', [
                '%nickname%' => new Fragment($result->targetNickname ?? '', Component::Emphasis),
            ], Role::Information),
            ManageChannelAkickOutcome::Enforced => $this->presentEnforced($context, $channelName, $performedBy, $result),
            ManageChannelAkickOutcome::EnforceNoMatch => $context->reply('akick.enforce.no_matches', [
                '%channel%' => new Fragment($channelName, Component::Emphasis),
            ], Role::Information),
        };
    }

    /** @param list<ChannelAkickEntryView> $entries */
    private function presentList(ChanServContext $context, string $channelName, array $entries): void
    {
        $context->replyRaw($context->renderComponent(Component::Positive, 'akick.list.header', ['%channel%' => new Fragment($channelName, Component::Emphasis)]));
        foreach ($entries as $index => $entry) {
            $context->replyRaw($context->renderComponent(Component::IndentedRow, 'akick.list.entry', [
                '%index%' => (string) ($index + 1),
                '%mask%' => new Fragment($entry->mask, Component::Danger),
                '%reason%' => $entry->reason ?? $context->trans('label.no_reason'),
                '%nickname%' => $entry->creatorNickname ?? $context->trans('label.unknown_creator'),
                '%expiration%' => null === $entry->expiresAt
                    ? $context->trans('label.never')
                    : $context->formatDate($entry->expiresAt),
                '%uses%' => (string) $entry->uses,
            ]));
        }
    }

    private function presentAdded(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $mask = $result->mask ?? '';
        $context->reply('akick.add.done', ['%mask%' => new Fragment($mask, Component::Emphasis)], Role::Success);
        $notice = $context->renderReply(Role::Information, 'akick.add.notice_channel', [
            '%from%' => new Fragment($performedBy ?? '', Component::Actor),
            '%mask%' => new Fragment($mask, Component::Emphasis),
            '%reason%' => null === $result->reason ? $context->trans('label.no_reason') : $result->reason,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentDeleted(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $mask = $result->mask ?? '';
        $context->reply('akick.del.done', ['%mask%' => new Fragment($mask, Component::Emphasis)], Role::Success);
        $notice = $context->renderReply(Role::Information, 'akick.del.notice_channel', [
            '%from%' => new Fragment($performedBy ?? '', Component::Actor),
            '%mask%' => new Fragment($mask, Component::Emphasis),
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentCleared(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $context->reply('akick.clear.done', [
            '%channel%' => new Fragment($channelName, Component::Emphasis),
            '%count%' => (string) $result->count,
        ], Role::Success);
        $notice = $context->renderReply(Role::Information, 'akick.clear.notice_channel', [
            '%from%' => new Fragment($performedBy ?? '', Component::Actor),
            '%channel%' => new Fragment($channelName, Component::Emphasis),
            '%count%' => (string) $result->count,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentEnforced(ChanServContext $context, string $channelName, ?string $performedBy, ManageChannelAkickResult $result): void
    {
        $count = $result->count;
        $context->reply('akick.enforce.done', [
            '%channel%' => new Fragment($channelName, Component::Emphasis),
            '%count%' => (string) $count,
        ], Role::Success);
        $notice = $context->renderReply(Role::Information, 'akick.enforce.notice_channel', [
            '%from%' => new Fragment($performedBy ?? '', Component::Actor),
            '%channel%' => new Fragment($channelName, Component::Emphasis),
            '%count%' => (string) $count,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $notice);
    }

    private function presentViewed(ChanServContext $context, string $channelName, ManageChannelAkickResult $result): void
    {
        $entry = $result->entry;
        if (null === $entry) {
            return;
        }

        $presenter = new ChanServDetailPresenter();
        $context->replyRaw($presenter->header($context, 'akick.view.header', $channelName));

        $context->replyRaw($presenter->row($context, 'akick.view.reason', [
            '%reason%' => $entry->reason ?? $context->trans('label.no_reason'),
        ]));

        $context->replyRaw($presenter->row($context, 'akick.view.author', [
            '%author%' => $entry->creatorNickname ?? $context->trans('label.unknown_creator'),
        ]));

        $context->replyRaw($presenter->row($context, 'akick.view.created', [
            '%date%' => null === $entry->createdAt ? $context->trans('label.unknown') : $context->formatDate($entry->createdAt),
        ]));

        $context->replyRaw($presenter->row($context, 'akick.view.expires', [
            '%date%' => null === $entry->expiresAt ? $context->trans('label.never') : $context->formatDate($entry->expiresAt),
        ]));

        $context->replyRaw($presenter->row($context, 'akick.view.uses', [
            '%uses%' => (string) $entry->uses,
        ]));

        $context->replyRaw($presenter->row($context, 'akick.view.last_used', [
            '%date%' => null === $entry->lastUsedAt ? $context->trans('label.never') : $context->formatDate($entry->lastUsedAt),
        ]));

        $context->replyRaw($context->renderSeparator(29));
    }

    private function presentChecked(ChanServContext $context, string $channelName, ManageChannelAkickResult $result): void
    {
        $context->replyRaw($context->renderComponent(Component::Positive, 'akick.check.header', [
            '%nickname%' => new Fragment($result->targetNickname ?? '', Component::Emphasis),
            '%channel%' => new Fragment($channelName, Component::Emphasis),
        ]));
        foreach ($result->entries as $index => $entry) {
            $context->replyRaw($context->renderComponent(Component::IndentedRow, 'akick.check.entry', [
                '%index%' => (string) ($entry->entryNumber ?? ($index + 1)),
                '%mask%' => new Fragment($entry->mask, Component::Danger),
                '%reason%' => $entry->reason ?? $context->trans('label.no_reason'),
            ]));
        }
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
