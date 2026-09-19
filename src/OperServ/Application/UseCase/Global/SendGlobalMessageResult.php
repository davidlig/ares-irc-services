<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Global;

/** Semantic outcome for GLOBAL; message content is intentionally never exposed for audit/presentation. */
final readonly class SendGlobalMessageResult
{
    private function __construct(
        public SendGlobalMessageOutcome $outcome,
        public string $senderNickname,
        public ?int $recipientCount = null,
        public ?string $maskError = null,
    ) {}

    public static function sent(string $senderNickname, int $recipientCount): self
    {
        return new self(SendGlobalMessageOutcome::Sent, $senderNickname, $recipientCount);
    }

    public static function invalidMessageType(): self
    {
        return new self(SendGlobalMessageOutcome::InvalidMessageType, '');
    }

    public static function invalidMask(string $error): self
    {
        return new self(SendGlobalMessageOutcome::InvalidMask, '', maskError: $error);
    }

    public static function nicknameConnected(string $nickname): self
    {
        return new self(SendGlobalMessageOutcome::NicknameConnected, $nickname);
    }

    public static function nicknameRegistered(string $nickname): self
    {
        return new self(SendGlobalMessageOutcome::NicknameRegistered, $nickname);
    }

    public static function networkUnavailable(string $nickname): self
    {
        return new self(SendGlobalMessageOutcome::NetworkUnavailable, $nickname);
    }
}
