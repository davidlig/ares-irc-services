<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

interface HistoryNickHandlerInterface
{
    public function handle(HistoryNick $command): HistoryNickResult;
}
