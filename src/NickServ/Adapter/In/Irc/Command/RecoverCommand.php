<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\EmailMasker;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Recover\RecoverNick;
use App\NickServ\Application\UseCase\Recover\RecoverNickHandlerInterface;
use App\NickServ\Application\UseCase\Recover\RecoverNickOutcome;
use App\NickServ\Application\UseCase\Recover\RecoverNickResult;

use function base64_decode;
use function ceil;
use function inet_ntop;
use function sprintf;

final readonly class RecoverCommand implements NickServCommandInterface
{
    public function __construct(private RecoverNickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'RECOVER';
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
        return 'recover.syntax';
    }

    public function getHelpKey(): string
    {
        return 'recover.help';
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

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
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
        ));

        $this->present($context, $result);
    }

    private function present(NickServContext $context, RecoverNickResult $result): void
    {
        switch ($result->outcome) {
            case RecoverNickOutcome::NotRegistered:
                $context->reply('recover.not_registered', ['nickname' => $result->nickname ?? '']);
                break;
            case RecoverNickOutcome::Pending:
                $context->reply('recover.pending', ['nickname' => $result->nickname ?? '']);
                break;
            case RecoverNickOutcome::Suspended:
                $context->reply('recover.suspended', [
                    'nickname' => $result->nickname ?? '',
                    'reason' => $result->reason ?? '',
                ]);
                break;
            case RecoverNickOutcome::Forbidden:
                $context->reply('recover.forbidden', ['nickname' => $result->nickname ?? '']);
                break;
            case RecoverNickOutcome::NoEmail:
                $context->reply('recover.no_email', ['nickname' => $result->nickname ?? '']);
                break;
            case RecoverNickOutcome::Throttled:
                $context->reply('recover.throttled', [
                    'minutes' => (string) (int) ceil($result->retryAfterSeconds / 60),
                ]);
                break;
            case RecoverNickOutcome::MailDeliveryFailed:
                $context->reply('error.mail_failed');
                break;
            case RecoverNickOutcome::TokenSent:
                $context->reply('recover.email_sent', [
                    'email_hint' => EmailMasker::mask($result->email ?? ''),
                ]);
                break;
            case RecoverNickOutcome::InvalidToken:
                $context->reply('recover.invalid_token', ['nickname' => $result->nickname ?? '']);
                break;
            case RecoverNickOutcome::PasswordReset:
                $identifyCmd = '/msg NickServ IDENTIFY ' . ($result->nickname ?? '') . ' ' . ($result->temporaryPassword ?? '');
                $context->reply('recover.success_identify', ['identify_cmd' => $identifyCmd]);
                $context->reply('recover.success_then_change');
                break;
        }
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
