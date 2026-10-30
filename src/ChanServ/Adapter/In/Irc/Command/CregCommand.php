<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistration;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationOutcome;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function array_slice;
use function implode;
use function in_array;
use function max;
use function strtoupper;
use function trim;

final readonly class CregCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(
        private ListPendingRegistrationsHandler $pending,
        private ReviewRegistrationHandlerInterface $review,
    ) {}

    public function getName(): string
    {
        return 'CREG';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            'creg.syntax',
            [new Argument('action', alternatives: ['PENDING', 'ACCEPT', 'REJECT'])],
            variants: [
                'PENDING' => new CommandArgumentDefinition('creg.pending.syntax', [new Argument('page', optional: true, digits: true)]),
                'ACCEPT' => new CommandArgumentDefinition('creg.accept.syntax', [new Argument('channel', prefix: '#')]),
                'REJECT' => new CommandArgumentDefinition('creg.reject.syntax', [
                    new Argument('channel', prefix: '#'),
                    new Argument('reason', rest: true, nonBlank: true),
                ]),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'creg.help';
    }

    public function getExampleKey(): string
    {
        return 'creg.example';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'creg.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'PENDING', 'desc_key' => 'creg.pending.short', 'help_key' => 'creg.pending.help', 'syntax_key' => 'creg.pending.syntax', 'example_key' => 'creg.pending.example'],
            ['name' => 'ACCEPT', 'desc_key' => 'creg.accept.short', 'help_key' => 'creg.accept.help', 'syntax_key' => 'creg.accept.syntax', 'example_key' => 'creg.accept.example'],
            ['name' => 'REJECT', 'desc_key' => 'creg.reject.short', 'help_key' => 'creg.reject.help', 'syntax_key' => 'creg.reject.syntax', 'example_key' => 'creg.reject.example'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return ChanServPermission::REGISTRATION_REVIEW;
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
        if (!$this->validateArguments($context)) {
            return CommandOutcome::rejected();
        }
        $action = strtoupper($context->args[0] ?? '');
        if ('PENDING' === $action) {
            $page = max(1, (int) ($context->args[1] ?? 1));
            $pending = $this->pending->page($page);
            $context->replyRaw($context->renderComponent(Component::Muted, 'creg.pending_header', ['%page%' => (string) $page]));
            foreach ($pending as $item) {
                $context->replyRaw($context->renderComponent(Component::IndentedRow, 'creg.pending_item', [
                    '%channel%' => $item->channelName,
                    '%requester%' => $item->requesterNickname ?? (string) $item->requesterAccountId,
                    '%submitted%' => $context->formatDate($item->submittedAt),
                    '%description%' => $item->description,
                ]));
            }

            return CommandOutcome::success();
        }

        $channelName = $context->getChannelNameArg(1);
        $operator = $context->sender;
        if (null === $channelName || null === $operator || null === $context->senderAccount) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        $reason = 'REJECT' === $action ? trim(implode(' ', array_slice($context->args, 2))) : null;
        $channelView = $context->getChannelView($channelName);
        $pending = $this->pending->find($channelName);
        $requester = null !== $pending && null !== $pending->requesterNickname
            ? $context->getUserLookup()->findByNick($pending->requesterNickname)
            : null;
        $requesterHasRequiredChannelRank = null !== $channelView && null !== $requester
            && $requester->isIdentified
            && $this->hasRequiredChannelRank($channelView, $requester->uid);
        $result = $this->review->handle(new ReviewRegistration(
            channelName: $channelName,
            channelExistsOnNetwork: null !== $channelView,
            operatorAccountId: $context->senderAccount->id,
            operatorNickname: $operator->nick,
            occurredAt: new DateTimeImmutable(),
            rejectionReason: $reason,
            requesterHasRequiredChannelRank: $requesterHasRequiredChannelRank,
            requesterUid: $requester?->uid,
            requesterNickname: $requester?->nick,
            requesterIdentified: $requester->isIdentified ?? false,
            requesterIrcOperator: $requester->isOper ?? false,
        ));
        if (ReviewRegistrationOutcome::NotPending === $result->outcome) {
            $context->reply('creg.not_pending', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        if (ReviewRegistrationOutcome::ChannelNotOnNetwork === $result->outcome) {
            $context->reply('creg.channel_not_on_network', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        if (ReviewRegistrationOutcome::FounderLimitExceeded === $result->outcome) {
            $context->reply('creg.founder_limit', role: Role::Error);

            return CommandOutcome::rejected();
        }
        if (ReviewRegistrationOutcome::RequesterInactive === $result->outcome) {
            $context->reply('creg.requester_inactive', role: Role::Error);

            return CommandOutcome::rejected();
        }
        if (ReviewRegistrationOutcome::RequesterIneligible === $result->outcome) {
            $context->reply('creg.requester_ineligible', role: Role::Error);

            return CommandOutcome::rejected();
        }

        $accepted = ReviewRegistrationOutcome::Accepted === $result->outcome;
        $context->reply($accepted ? 'creg.accepted' : 'creg.rejected', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Success);

        return CommandOutcome::success(new IrcopAuditData(
            target: $channelName,
            reason: $reason,
            extra: ['decision' => $accepted ? 'accepted' : 'rejected'],
        ));
    }

    private function hasRequiredChannelRank(ChannelView $channelView, string $requesterUid): bool
    {
        foreach ($channelView->members as $member) {
            if ($requesterUid !== $member['uid']) {
                continue;
            }

            foreach ($member['prefixLetters'] ?? [$member['roleLetter']] as $letter) {
                if (in_array($letter, ['q', 'a', 'o'], true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
