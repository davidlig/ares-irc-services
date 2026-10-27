<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\NotifyChannelRegistration;

interface NotifyChannelRegistrationDecisionHandlerInterface
{
    public function handle(NotifyChannelRegistrationDecision $decision): void;
}
