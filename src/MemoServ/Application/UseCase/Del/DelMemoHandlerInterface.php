<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Del;

interface DelMemoHandlerInterface
{
    public function handle(DelMemo $command): DelMemoResult;
}
