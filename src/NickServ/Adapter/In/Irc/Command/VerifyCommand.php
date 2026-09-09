<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Verify\VerifyNickOutcome;
use App\NickServ\Application\UseCase\Verify\VerifyNickResult;

final readonly class VerifyCommand implements NickServCommandInterface
{
    public function __construct(private VerifyNickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'VERIFY';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'verify.syntax';
    }

    public function getHelpKey(): string
    {
        return 'verify.help';
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getShortDescKey(): string
    {
        return 'verify.short';
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

        $token = $context->args[0];

        $result = $this->handler->handle(new VerifyNick(
            nickname: $sender->nick,
            token: $token,
            senderUid: $sender->uid,
        ));

        $this->present($context, $result);

        return null;
    }

    private function present(NickServContext $context, VerifyNickResult $result): void
    {
        switch ($result->outcome) {
            case VerifyNickOutcome::Success:
                if (null !== $context->sender && null !== $result->nickname) {
                    $context->getNotifier()->setUserAccount($context->sender->uid, $result->nickname);
                    $context->reply('verify.success', ['nickname' => $result->nickname]);
                }
                break;
            case VerifyNickOutcome::NoPending:
                $context->reply('verify.no_pending');
                break;
            case VerifyNickOutcome::InvalidToken:
                $context->reply('verify.invalid_token');
                break;
        }
    }
}
