<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Security\IrcopAuthorizationSubject;

/**
 * Interface for contexts that support IRCOP permission checks.
 * All service contexts (NickServ, ChanServ, MemoServ, OperServ) must implement this.
 */
interface IrcopContextInterface extends IrcopAuthorizationSubject
{
    public function getSender(): ?SenderView;

    public function getSenderAccount(): ?RegisteredNick;

    /**
     * @param array<string, mixed> $parameters
     */
    public function reply(string $key, array $parameters = []): void;

    public function replyRaw(string $message): void;
}
