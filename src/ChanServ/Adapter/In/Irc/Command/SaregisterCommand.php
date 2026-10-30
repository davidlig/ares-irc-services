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
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

use function array_slice;
use function implode;

final readonly class SaregisterCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(private SaregisterChannelHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SAREGISTER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'saregister.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('nickname'),
                new Argument('description', rest: true),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'saregister.help';
    }

    public function getExampleKey(): string
    {
        return 'saregister.example';
    }

    public function getOrder(): int
    {
        return 72;
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

    /**
     * @return array<string, mixed>
     */
    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(ChanServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }

        if (!$this->validateArguments($context)) {
            return CommandOutcome::rejected();
        }

        $targetNickname = $context->args[1];
        $description = implode(' ', array_slice($context->args, 2));

        $result = $this->handler->handle(new SaregisterChannel(
            channelName: $channelName,
            targetNickname: $targetNickname,
            description: $description,
            actorNickname: $sender->nick,
            actorAccountId: $context->senderAccount?->id,
            actorIrcOperator: $sender->isOper,
            occurredAt: new DateTimeImmutable(),
        ));

        return $this->present($context, $channelName, $result);
    }

    private function present(ChanServContext $context, string $channelName, SaregisterChannelResult $result): CommandOutcome
    {
        return match ($result->outcome) {
            SaregisterChannelOutcome::Registered => $this->presentRegistered($context, $channelName, $result),
            SaregisterChannelOutcome::InvalidChannelName => $this->rejectInvalidChannel($context),
            SaregisterChannelOutcome::AlreadyRegistered => $this->rejectWithChannel($context, 'register.already_registered', $channelName),
            SaregisterChannelOutcome::PendingDeletion => $this->rejectWithChannel($context, 'register.pending_deletion', $channelName),
            SaregisterChannelOutcome::TargetNotFound => $this->rejectWithNickname($context, 'error.nick_not_registered', $result->targetNickname ?? ''),
            SaregisterChannelOutcome::TargetNotRegistered => $this->rejectWithNickname($context, 'set.founder.must_be_registered', $result->targetNickname ?? ''),
            SaregisterChannelOutcome::TargetSuspended => $this->rejectWithNickname($context, 'set.founder.suspended', $result->targetNickname ?? ''),
        };
    }

    private function presentRegistered(ChanServContext $context, string $channelName, SaregisterChannelResult $result): CommandOutcome
    {
        $founder = $result->targetNickname ?? '';
        $context->reply('saregister.success', [
            '%channel%' => new Fragment($channelName, Component::Emphasis),
            '%founder%' => new Fragment($founder, Component::Emphasis),
        ], Role::Success);

        return CommandOutcome::success(new IrcopAuditData(
            target: $channelName,
            extra: [
                'founder' => $founder,
            ],
        ));
    }

    private function rejectInvalidChannel(ChanServContext $context): CommandOutcome
    {
        $context->reply('error.invalid_channel', [
            '%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis),
        ], Role::Error);

        return CommandOutcome::rejected();
    }

    private function rejectWithChannel(ChanServContext $context, string $key, string $channelName): CommandOutcome
    {
        $context->reply($key, [
            '%channel%' => new Fragment($channelName, Component::Emphasis),
        ], Role::Error);

        return CommandOutcome::rejected();
    }

    private function rejectWithNickname(ChanServContext $context, string $key, string $nickname): CommandOutcome
    {
        $context->reply($key, [
            '%nickname%' => new Fragment($nickname, Component::Emphasis),
        ], Role::Error);

        return CommandOutcome::rejected();
    }
}
