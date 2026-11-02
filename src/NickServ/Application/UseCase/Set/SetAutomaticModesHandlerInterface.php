<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

use App\NickServ\Application\Model\NickOperationActor;

interface SetAutomaticModesHandlerInterface
{
    public function handle(NickOperationActor $actor, string $nickname, string $modes): SetAutomaticModesResult;
}
