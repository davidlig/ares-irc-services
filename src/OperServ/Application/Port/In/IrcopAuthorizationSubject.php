<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

/** Minimal authorization view implemented by IRC service command contexts. */
interface IrcopAuthorizationSubject
{
    public function getSenderNickname(): ?string;

    public function getSenderAccountId(): ?int;

    public function isSenderIdentified(): bool;

    public function isSenderIrcOperator(): bool;
}
