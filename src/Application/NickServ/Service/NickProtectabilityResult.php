<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Domain\NickServ\Entity\RegisteredNick;

readonly class NickProtectabilityResult
{
    private function __construct(
        public NickProtectabilityStatus $status,
        public string $nickname,
        public ?RegisteredNick $account = null,
    ) {
    }

    public static function allowed(string $nickname, ?RegisteredNick $account): self
    {
        return new self(NickProtectabilityStatus::Allowed, $nickname, $account);
    }

    public static function root(string $nickname): self
    {
        return new self(NickProtectabilityStatus::IsRoot, $nickname);
    }

    public static function ircop(string $nickname): self
    {
        return new self(NickProtectabilityStatus::IsIrcop, $nickname);
    }

    public static function service(string $nickname): self
    {
        return new self(NickProtectabilityStatus::IsService, $nickname);
    }

    public function isAllowed(): bool
    {
        return NickProtectabilityStatus::Allowed === $this->status;
    }
}
