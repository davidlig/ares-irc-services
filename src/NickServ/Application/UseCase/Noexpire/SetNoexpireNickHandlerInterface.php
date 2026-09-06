<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

interface SetNoexpireNickHandlerInterface
{
    public function handle(SetNoexpireNick $command): SetNoexpireNickResult;
}
