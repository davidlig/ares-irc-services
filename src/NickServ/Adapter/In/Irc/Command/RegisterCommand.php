<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Application\UseCase\Register\RegisterNickHandlerInterface;
use App\NickServ\Application\UseCase\Register\RegisterNickOutcome;
use App\NickServ\Application\UseCase\Register\RegisterNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function ceil;

/** IRC parsing and presentation adapter for REGISTER <password> [email]. */
final readonly class RegisterCommand implements NickServCommandInterface
{
    public function __construct(
        private RegisterNickHandlerInterface $handler,
        private int $verificationTokenTtlSeconds,
    ) {}

    public function getName(): string
    {
        return 'REGISTER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('register.syntax', [
            new Argument('password'),
            new Argument('email', optional: true),
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
        return 'register.help';
    }

    public function getExampleKey(): string
    {
        return 'register.example';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'register.short';
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
        return ['duration_seconds' => $this->verificationTokenTtlSeconds];
    }

    public function execute(NickServContext $context): null
    {
        $sender = $context->sender;
        if (null === $sender) {
            return null;
        }

        $this->present($context, $this->handler->handle(new RegisterNick(
            nickname: $sender->nick,
            password: $context->args[0],
            email: $context->args[1] ?? null,
            language: $context->getLanguage(),
            clientKey: self::clientKey($sender),
        )));

        return null;
    }

    private function present(NickServContext $context, RegisterNickResult $result): void
    {
        switch ($result->outcome) {
            case RegisterNickOutcome::TokenVerificationRequired:
                $context->replyRescueToken('rescue.issued', $result->token, null);
                break;
            case RegisterNickOutcome::TemporarilyUnavailable:
                $context->reply('register.token_unavailable', [], Role::Error);
                break;
            case RegisterNickOutcome::VerificationRequired:
                $duration = $context->formatDuration($this->verificationTokenTtlSeconds);
                $email = $result->email ?? '';
                $context->reply('register.pending', [
                    'email' => $email,
                    'duration' => $duration,
                    'first' => $context->localizedFragment('register.pending_line_1', Component::Text, [
                        'email' => new Fragment($email, Component::Emphasis),
                    ]),
                    'second' => $context->localizedFragment('register.pending_line_2', Component::IndentedRow, [
                        'verify' => new Fragment('VERIFY <token>', Component::Command),
                    ]),
                    'third' => $context->localizedFragment('register.pending_line_3', Component::IndentedRow, [
                        'resend' => new Fragment('RESEND', Component::Command),
                    ]),
                    'fourth' => $context->localizedFragment('register.pending_line_4', Component::IndentedRow, [
                        'duration' => new Fragment($duration, Component::Emphasis),
                    ]),
                ], Role::Information);
                break;
            case RegisterNickOutcome::Throttled:
                $context->reply('register.throttled', [
                    'minutes' => new Fragment((string) (int) ceil($result->retryAfterSeconds / 60), Component::Emphasis),
                ], Role::Error);
                break;
            case RegisterNickOutcome::GuestPrefixForbidden:
                $context->reply('register.guest_prefix_forbidden', ['prefix' => new Fragment($result->guestPrefix ?? '', Component::Emphasis)], Role::Error);
                break;
            case RegisterNickOutcome::BlockedEmailDomain:
                $context->replyCommonError('error.email_domain_blocked');
                break;
            case RegisterNickOutcome::InvalidEmail:
                $context->reply('register.invalid_email', [], Role::Error);
                break;
            case RegisterNickOutcome::EmailAlreadyUsed:
                $context->reply('register.email_already_used', ['email' => new Fragment($result->email ?? '', Component::Emphasis)], Role::Error);
                break;
            case RegisterNickOutcome::AlreadyPending:
                $context->reply('register.already_pending', [
                    'nickname' => new Fragment($result->nickname ?? '', Component::Emphasis),
                    'verify' => new Fragment('VERIFY <token>', Component::Command),
                    'resend' => new Fragment('RESEND', Component::Command),
                ], Role::Information);
                break;
            case RegisterNickOutcome::Forbidden:
                $context->reply('register.forbidden', ['nickname' => new Fragment($result->nickname ?? '', Component::Emphasis)], Role::Error);
                break;
            case RegisterNickOutcome::PendingDeletion:
                $context->reply('register.pending_deletion', ['nickname' => new Fragment($result->nickname ?? '', Component::Emphasis)], Role::Error);
                break;
            case RegisterNickOutcome::AlreadyRegistered:
                $context->reply('register.already_registered', ['nickname' => new Fragment($result->nickname ?? '', Component::Emphasis)], Role::Error);
                break;
            case RegisterNickOutcome::MailDeliveryFailed:
                $context->replyCommonError('error.mail_failed');
                break;
        }
    }

    private static function clientKey(SenderView $sender): string
    {
        return match (true) {
            '' !== $sender->ipBase64 && '*' !== $sender->ipBase64 => 'ip:' . $sender->ipBase64,
            '' !== $sender->cloakedHost => 'cloak:' . $sender->cloakedHost,
            '' !== $sender->hostname => 'host:' . $sender->hostname,
            default => 'uid:' . $sender->uid,
        };
    }
}
