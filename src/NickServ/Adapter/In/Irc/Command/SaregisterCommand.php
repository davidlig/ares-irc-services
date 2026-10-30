<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Saregister\SaregisterNick;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickHandlerInterface;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickOutcome;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

final readonly class SaregisterCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private SaregisterNickHandlerInterface $handler) {}

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
        return new CommandArgumentDefinition('saregister.syntax', [
            new Argument('nickname'),
            new Argument('password'),
            new Argument('email', optional: true),
        ], allowExtra: false);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
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
        return 64;
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
        return NickServPermission::SAREGISTER;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (null !== $validation->syntaxKey) {
            $context->replySyntax($validation->syntaxKey);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new SaregisterNick(
            nickname: $context->args[0],
            password: $context->args[1],
            email: $context->args[2] ?? null,
            language: $context->getLanguage(),
            actorNickname: $sender->nick,
            actorAccountId: $context->senderAccount?->getId(),
            actorIrcOperator: $sender->isOper,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, SaregisterNickResult $result): CommandOutcome
    {
        return match ($result->outcome) {
            SaregisterNickOutcome::RegisteredToken => $this->presentRegisteredToken($context, $result),
            SaregisterNickOutcome::RegisteredEmail => $this->presentRegisteredEmail($context, $result),
            SaregisterNickOutcome::InvalidNickname => $this->rejectWithFragment($context, 'saregister.invalid_nickname', 'nickname', $result->nickname ?? ''),
            SaregisterNickOutcome::GuestPrefixForbidden => $this->rejectWithFragment($context, 'register.guest_prefix_forbidden', 'prefix', $result->guestPrefix ?? ''),
            SaregisterNickOutcome::InvalidEmail => $this->rejectKey($context, 'register.invalid_email'),
            SaregisterNickOutcome::BlockedEmailDomain => $this->rejectCommon($context, 'error.email_domain_blocked'),
            SaregisterNickOutcome::EmailAlreadyUsed => $this->rejectWithFragment($context, 'register.email_already_used', 'email', $result->email ?? ''),
            SaregisterNickOutcome::AlreadyPending => $this->rejectKey($context, 'register.already_pending', [
                'nickname' => new Fragment($result->nickname ?? '', Component::Emphasis),
                'verify' => new Fragment('VERIFY <token>', Component::Command),
                'resend' => new Fragment('RESEND', Component::Command),
            ], Role::Information),
            SaregisterNickOutcome::Forbidden => $this->rejectWithFragment($context, 'register.forbidden', 'nickname', $result->nickname ?? ''),
            SaregisterNickOutcome::PendingDeletion => $this->rejectWithFragment($context, 'register.pending_deletion', 'nickname', $result->nickname ?? ''),
            SaregisterNickOutcome::AlreadyRegistered => $this->rejectWithFragment($context, 'register.already_registered', 'nickname', $result->nickname ?? ''),
            SaregisterNickOutcome::MailDeliveryFailed => $this->rejectCommon($context, 'error.mail_failed'),
        };
    }

    private function presentRegisteredToken(NickServContext $context, SaregisterNickResult $result): CommandOutcome
    {
        $context->replyEmphasized('saregister.success_token', [
            '%nickname%' => $result->nickname ?? '',
            '%token%' => $result->token ?? '',
        ], Role::Success, ['nickname', 'token']);

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->nickname ?? '',
        ));
    }

    private function presentRegisteredEmail(NickServContext $context, SaregisterNickResult $result): CommandOutcome
    {
        $context->replyEmphasized('saregister.success_email', [
            '%nickname%' => $result->nickname ?? '',
            '%email%' => $result->email ?? '',
        ], Role::Success, ['nickname', 'email']);

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->nickname ?? '',
            extra: ['email' => $result->email],
        ));
    }

    private function rejectWithFragment(NickServContext $context, string $key, string $param, string $value): CommandOutcome
    {
        $context->reply($key, [$param => new Fragment($value, Component::Emphasis)], Role::Error);

        return CommandOutcome::rejected();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rejectKey(NickServContext $context, string $key, array $params = [], Role $role = Role::Error): CommandOutcome
    {
        $context->reply($key, $params, $role);

        return CommandOutcome::rejected();
    }

    private function rejectCommon(NickServContext $context, string $key): CommandOutcome
    {
        $context->replyCommonError($key);

        return CommandOutcome::rejected();
    }
}
