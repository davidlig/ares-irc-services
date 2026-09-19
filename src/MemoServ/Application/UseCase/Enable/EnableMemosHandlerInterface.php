<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Enable;

interface EnableMemosHandlerInterface
{
    public function handle(EnableMemos $command): EnableMemosResult;
}
