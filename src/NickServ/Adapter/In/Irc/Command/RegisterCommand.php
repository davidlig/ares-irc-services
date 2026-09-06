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

use function ceil;

/** IRC parsing and presentation adapter for REGISTER <password> <email>. */
final readonly class RegisterCommand implements NickServCommandInterface
{
    public function __construct(private RegisterNickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'REGISTER';
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
        return 'register.syntax';
    }

    public function getHelpKey(): string
    {
        return 'register.help';
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
        return [];
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $this->present($context, $this->handler->handle(new RegisterNick(
            nickname: $sender->nick,
            password: $context->args[0],
            email: $context->args[1],
            language: $context->getLanguage(),
            clientKey: self::clientKey($sender),
        )));
    }

    private function present(NickServContext $context, RegisterNickResult $result): void
    {
        switch ($result->outcome) {
            case RegisterNickOutcome::VerificationRequired:
                $context->reply('register.pending', ['email' => $result->email]);
                break;
            case RegisterNickOutcome::Throttled:
                $context->reply('register.throttled', [
                    'minutes' => (string) (int) ceil($result->retryAfterSeconds / 60),
                ]);
                break;
            case RegisterNickOutcome::GuestPrefixForbidden:
                $context->reply('register.guest_prefix_forbidden', ['prefix' => $result->guestPrefix]);
                break;
            case RegisterNickOutcome::InvalidEmail:
                $context->reply('register.invalid_email');
                break;
            case RegisterNickOutcome::EmailAlreadyUsed:
                $context->reply('register.email_already_used', ['email' => $result->email]);
                break;
            case RegisterNickOutcome::AlreadyPending:
                $context->reply('register.already_pending', ['nickname' => $result->nickname]);
                break;
            case RegisterNickOutcome::Forbidden:
                $context->reply('register.forbidden', ['nickname' => $result->nickname]);
                break;
            case RegisterNickOutcome::PendingDeletion:
                $context->reply('register.pending_deletion', ['nickname' => $result->nickname]);
                break;
            case RegisterNickOutcome::AlreadyRegistered:
                $context->reply('register.already_registered', ['nickname' => $result->nickname]);
                break;
            case RegisterNickOutcome::MailDeliveryFailed:
                $context->reply('error.mail_failed');
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
