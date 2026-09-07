<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Read;

interface ReadMemoHandlerInterface
{
    public function handle(ReadMemo $command): ReadMemoResult;
}
