<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Send;

interface SendMemoHandlerInterface
{
    public function handle(SendMemo $command): SendMemoResult;
}
