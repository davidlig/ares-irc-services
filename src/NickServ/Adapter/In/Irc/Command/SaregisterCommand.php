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

use function count;
use function trim;

/**
 * SAREGISTER <nickname> <password> [email] — registers a nickname on behalf of another user.
 *
 * The syntax depends on the network registration mode: TOKEN requires no address, EMAIL does.
 */
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

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'saregister.syntax';
    }

    public function getHelpKey(): string
    {
        return 'saregister.help';
    }

    public function getOrder(): int
    {
        return 68;
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

        $nickname = trim($context->args[0] ?? '');
        $password = $context->args[1] ?? '';
        $email = isset($context->args[2]) ? trim($context->args[2]) : null;

        if ('' === $nickname || '' === $password || count($context->args) > 3 || (null !== $email && '' === $email)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new SaregisterNick(
            nickname: $nickname,
            password: $password,
            email: $email,
            language: $context->getLanguage(),
        ));

        $this->present($context, $result);

        if (SaregisterNickOutcome::Registered !== $result->outcome
            && SaregisterNickOutcome::RegisteredWithToken !== $result->outcome
            && SaregisterNickOutcome::RegisteredWithoutMail !== $result->outcome
        ) {
            return CommandOutcome::rejected();
        }

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->nickname,
            extra: ['mode' => $result->mode->value],
        ));
    }

    private function present(NickServContext $context, SaregisterNickResult $result): void
    {
        switch ($result->outcome) {
            case SaregisterNickOutcome::Registered:
                $context->reply('saregister.created', [
                    'nickname' => $result->nickname,
                    'email' => $result->email ?? '',
                ]);
                break;
            case SaregisterNickOutcome::RegisteredWithToken:
                $context->reply('saregister.created_with_token', [
                    'nickname' => $result->nickname,
                    'token' => $result->token ?? '',
                ]);
                break;
            case SaregisterNickOutcome::RegisteredWithoutMail:
                $context->reply('saregister.created_without_mail', [
                    'nickname' => $result->nickname,
                    'email' => $result->email ?? '',
                ]);
                break;
            case SaregisterNickOutcome::InvalidEmail:
                $context->reply('register.invalid_email');
                break;
            case SaregisterNickOutcome::EmailAlreadyUsed:
                $context->reply('register.email_already_used', ['email' => $result->email ?? '']);
                break;
            case SaregisterNickOutcome::AlreadyPending:
                $context->reply('register.already_pending', ['nickname' => $result->nickname]);
                break;
            case SaregisterNickOutcome::Forbidden:
                $context->reply('register.forbidden', ['nickname' => $result->nickname]);
                break;
            case SaregisterNickOutcome::PendingDeletion:
                $context->reply('register.pending_deletion', ['nickname' => $result->nickname]);
                break;
            case SaregisterNickOutcome::AlreadyRegistered:
                $context->reply('register.already_registered', ['nickname' => $result->nickname]);
                break;
        }
    }
}
