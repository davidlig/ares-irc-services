<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Protocol;

use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;

final readonly class ProtocolNickChangeIdentificationPolicy implements NickChangeIdentificationPolicy
{
    public function __construct(private ActiveConnectionHolderInterface $connectionHolder) {}

    public function preservesIdentification(): bool
    {
        return $this->connectionHolder->getProtocolModule() instanceof NickChangePreservesIdentificationInterface;
    }
}
