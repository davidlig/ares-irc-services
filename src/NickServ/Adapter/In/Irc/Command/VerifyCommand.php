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
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function base64_decode;
use function inet_ntop;

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
            actorNickname: $sender->nick,
            sourceIp: self::decodeIp($sender->ipBase64),
        ));

        $this->present($context, $result);

        return null;
    }

    private static function decodeIp(string $ipBase64): ?string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return null;
        }

        $binary = base64_decode($ipBase64, true);
        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }

    private function present(NickServContext $context, VerifyNickResult $result): void
    {
        switch ($result->outcome) {
            case VerifyNickOutcome::CredentialConfirmed:
                $context->reply('rescue.confirmed', [], Role::Success);
                break;
            case VerifyNickOutcome::LockedOut:
                $context->reply('rescue.locked_out', ['seconds' => new Fragment((string) $result->retryAfterSeconds, Component::Emphasis)], Role::Error);
                break;
            case VerifyNickOutcome::Success:
                if (null !== $context->sender && null !== $result->nickname) {
                    $context->getNotifier()->setUserAccount($context->sender->uid, $result->nickname);
                    $context->reply('verify.success', ['nickname' => new Fragment($result->nickname, Component::Emphasis)], Role::Success);
                }
                break;
            case VerifyNickOutcome::SuccessNativeAuthenticationRequired:
                if (null !== $result->nickname) {
                    $context->reply('verify.success_native', ['nickname' => new Fragment($result->nickname, Component::Emphasis)], Role::Success);
                    $context->replyNativeAuthentication();
                }
                break;
            case VerifyNickOutcome::NoPending:
                $context->reply('verify.no_pending', [], Role::Error);
                break;
            case VerifyNickOutcome::InvalidToken:
                $context->reply('verify.invalid_token', [], Role::Error);
                break;
        }
    }
}
