<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Resend\ResendVerification;
use App\NickServ\Application\UseCase\Resend\ResendVerificationHandlerInterface;
use App\NickServ\Application\UseCase\Resend\ResendVerificationOutcome;
use App\NickServ\Application\UseCase\Resend\ResendVerificationResult;

use function ceil;

final readonly class ResendCommand implements NickServCommandInterface
{
    public function __construct(private ResendVerificationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'RESEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'resend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'resend.help';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'resend.short';
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

        $result = $this->handler->handle(new ResendVerification(
            nickname: $sender->nick,
            language: $context->getLanguage(),
        ));

        $this->present($context, $result);

        return null;
    }

    private function present(NickServContext $context, ResendVerificationResult $result): void
    {
        switch ($result->outcome) {
            case ResendVerificationOutcome::Success:
                $context->reply('resend.success', ['email' => $result->email ?? '']);
                break;
            case ResendVerificationOutcome::NoPending:
                $context->reply('resend.no_pending');
                break;
            case ResendVerificationOutcome::Throttled:
                $context->reply('resend.throttled', [
                    'minutes' => (string) (int) ceil($result->retryAfterSeconds / 60),
                ]);
                break;
            case ResendVerificationOutcome::MailDeliveryFailed:
                $context->reply('error.mail_failed');
                break;
        }
    }
}
