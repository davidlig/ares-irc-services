<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/** Minimal authorization view implemented by IRC service command contexts. */
interface IrcopAuthorizationSubject
{
    public function getSenderAccountId(): ?int;
}
