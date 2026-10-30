<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\UseCase\Recover\RecoverNick;
use App\NickServ\Application\UseCase\Recover\RecoverNickHandlerInterface;
use App\NickServ\Application\UseCase\Recover\RecoverNickOutcome;
use App\NickServ\Application\UseCase\Recover\RecoverNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use App\Shared\Application\EmailHintMasker;

use function base64_decode;
use function ceil;
use function inet_ntop;
use function sprintf;

final readonly class RecoverCommand implements NickServCommandInterface
{
    public function __construct(
        private RecoverNickHandlerInterface $handler,
        private NicknameAuthenticationModeQuery $authenticationMode,
    ) {}

    public function getName(): string
    {
        return 'RECOVER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('recover.syntax', [
            new Argument('nickname'),
            new Argument('token', optional: true),
        ]);
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
        return 'recover.help';
    }

    public function getExampleKey(): string
    {
        return 'recover.example';
    }

    public function getOrder(): int
    {
        return 7;
    }

    public function getShortDescKey(): string
    {
        return 'recover.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): null
    {
        $sender = $context->sender;
        if (null === $sender) {
            return null;
        }

        $targetNick = $context->args[0];
        $token = $context->args[1] ?? null;

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);

        $result = $this->handler->handle(new RecoverNick(
            nickname: $targetNick,
            token: $token,
            senderNick: $sender->nick,
            senderAccountId: $context->senderAccount?->getId(),
            senderIp: $ip,
            senderHost: $host,
            clientKey: new NickServClientKeyResolver()->getClientKey(new NetworkUser($sender->uid, $sender->nick, $sender->ident, $sender->hostname, $sender->cloakedHost, $sender->ipBase64)),
        ));

        $this->present($context, $result);

        return null;
    }

    private function present(NickServContext $context, RecoverNickResult $result): void
    {
        switch ($result->outcome) {
            case RecoverNickOutcome::NotRegistered:
                $context->replyMissingNick('error.nick_not_registered', $result->nickname ?? '');
                break;
            case RecoverNickOutcome::Pending:
                $context->replyWithLocalizedFragments('recover.pending', ['nickname' => new Fragment($result->nickname ?? '', Component::Emphasis)], Role::Information, [
                    'command_0' => ['recover.pending_command_0', Component::Command],
                    'command_1' => ['recover.pending_command_1', Component::Command],
                ]);
                break;
            case RecoverNickOutcome::Suspended:
                $context->replyEmphasized('recover.suspended', [
                    'nickname' => $result->nickname ?? '',
                    'reason' => $result->reason ?? '',
                ], Role::Error, ['nickname']);
                break;
            case RecoverNickOutcome::Forbidden:
                $context->replyEmphasized('recover.forbidden', ['nickname' => $result->nickname ?? ''], Role::Error, ['nickname']);
                break;
            case RecoverNickOutcome::Instructions:
                $context->replyWithLocalizedFragments('recover.instructions', ['nickname' => $result->nickname ?? ''], Role::Information, [
                    'command_0' => ['recover.instructions_command_0', Component::Command],
                    'command_1' => ['recover.instructions_command_1', Component::Command],
                    'command_2' => ['recover.instructions_command_2', Component::Command],
                ]);
                break;
            case RecoverNickOutcome::CredentialUnavailable:
                $context->replyRescueUnavailable();
                break;
            case RecoverNickOutcome::PendingDeletion:
                $context->replyEmphasized('recover.pending_deletion', ['nickname' => $result->nickname ?? ''], Role::Error, ['nickname']);
                break;
            case RecoverNickOutcome::Throttled:
                $context->replyEmphasized('recover.throttled', [
                    'minutes' => (string) (int) ceil($result->retryAfterSeconds / 60),
                ], Role::Error, ['minutes']);
                break;
            case RecoverNickOutcome::InvalidToken:
                $context->replyEmphasized('recover.invalid_token', ['nickname' => $result->nickname ?? ''], Role::Error, []);
                break;
            case RecoverNickOutcome::NoEmail:
                $context->replyEmphasized('recover.no_email', ['nickname' => $result->nickname ?? ''], Role::Error, ['nickname']);
                break;
            case RecoverNickOutcome::MailDeliveryFailed:
                $context->replyCommonError('error.mail_failed');
                break;
            case RecoverNickOutcome::TemporarilyUnavailable:
                $context->replyEmphasized('rescue.temporarily_unavailable', [], Role::Error, []);
                break;
            case RecoverNickOutcome::TokenSent:
                $context->replyEmphasized('recover.email_sent', ['email_hint' => EmailHintMasker::mask($result->email ?? '')], Role::Information, ['email_hint']);
                break;
            case RecoverNickOutcome::PasswordReset:
                $identifyCmd = $this->passwordResetAuthenticationCommand($result);
                $context->reply('recover.success_identify', ['identify_cmd' => new Fragment($identifyCmd, Component::Command)], Role::Success);
                $context->replyComponent(Component::Text, 'recover.success_then_change', [], [
                    'command_0' => ['recover.success_then_change_command_0', Component::Command],
                ]);
                if ($result->replacementUnavailable) {
                    $context->replyEmphasized('rescue.replacement_unavailable', [], Role::Warning, []);
                } elseif ($result->tokenPromoted) {
                    $context->replyEmphasized('rescue.replaced', ['token' => $result->rescueToken], Role::Success, []);
                } else {
                    $context->replyRescueToken('rescue.optional_replacement', $result->rescueToken, Role::Information);
                }
                break;
        }
    }

    private function passwordResetAuthenticationCommand(RecoverNickResult $result): string
    {
        $nickname = $result->nickname ?? '';
        $temporaryPassword = $result->temporaryPassword ?? '';

        if (NicknameAuthenticationMode::NativeNick === $this->authenticationMode->current()) {
            return sprintf(
                '/NICK %1$s:%2$s | /NICK %1$s!%2$s',
                $nickname,
                $temporaryPassword,
            );
        }

        return sprintf('/msg NickServ IDENTIFY %s %s', $nickname, $temporaryPassword);
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
