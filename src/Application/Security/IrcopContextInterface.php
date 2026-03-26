<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;

/**
 * Interface for contexts that support IRCOP permission checks.
 * All service contexts (NickServ, ChanServ, MemoServ, OperServ) must implement this.
 */
interface IrcopContextInterface
{
    public function getSender(): ?SenderView;

    public function getSenderAccount(): ?RegisteredNick;

    public function reply(string $key, array $parameters = []): void;

    public function replyRaw(string $message): void;
}
