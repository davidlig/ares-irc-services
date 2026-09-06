<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Verify;

interface VerifyNickHandlerInterface
{
    public function handle(VerifyNick $command): VerifyNickResult;
}
