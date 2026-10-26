<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\Out\User\IrcNetworkUserMapper;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Verify\VerifyNickOutcome;
use App\NickServ\Application\UseCase\Verify\VerifyNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

final readonly class VerifyCommand implements NickServCommandInterface
{
    public function __construct(
        private VerifyNickHandlerInterface $handler,
        private int $verificationTokenTtlSeconds,
    ) {}

    public function getName(): string
    {
        return 'VERIFY';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('verify.syntax', [new Argument('token')]);
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
        return 'verify.help';
    }

    public function getExampleKey(): string
    {
        return 'verify.example';
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
        return ['duration_seconds' => $this->verificationTokenTtlSeconds];
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
            clientKey: new NickServClientKeyResolver()->getClientKey(IrcNetworkUserMapper::map($sender)),
            authenticatedAccountId: $context->isSenderIdentified() ? $context->getSenderAccountId() : null,
        ));

        $this->present($context, $result);

        return null;
    }

    private function present(NickServContext $context, VerifyNickResult $result): void
    {
        switch ($result->outcome) {
            case VerifyNickOutcome::CredentialConfirmed:
                $context->reply('rescue.confirmed');
                break;
            case VerifyNickOutcome::LockedOut:
                $context->reply('rescue.locked_out', ['seconds' => (string) $result->retryAfterSeconds]);
                break;
            case VerifyNickOutcome::Success:
                if (null !== $context->sender && null !== $result->nickname) {
                    $context->getNotifier()->setUserAccount($context->sender->uid, $result->nickname);
                    $context->reply('verify.success', ['nickname' => $result->nickname]);
                }
                break;
            case VerifyNickOutcome::SuccessNativeAuthenticationRequired:
                if (null !== $result->nickname) {
                    $context->reply('verify.success_native', ['nickname' => $result->nickname]);
                    $context->reply('identify.native_authentication');
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
