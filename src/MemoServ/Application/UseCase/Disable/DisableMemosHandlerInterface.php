<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Disable;

interface DisableMemosHandlerInterface
{
    public function handle(DisableMemos $command): DisableMemosResult;
}
