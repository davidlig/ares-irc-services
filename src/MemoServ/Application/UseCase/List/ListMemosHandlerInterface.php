<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\List;

interface ListMemosHandlerInterface
{
    public function handle(ListMemos $command): ListMemosResult;
}
