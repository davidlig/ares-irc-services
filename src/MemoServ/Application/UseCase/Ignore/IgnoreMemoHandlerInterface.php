<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Ignore;

interface IgnoreMemoHandlerInterface
{
    public function handle(IgnoreMemo $command): IgnoreMemoResult;
}
