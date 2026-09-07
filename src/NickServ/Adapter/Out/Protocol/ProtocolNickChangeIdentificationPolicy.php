<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Protocol;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;

final readonly class ProtocolNickChangeIdentificationPolicy implements NickChangeIdentificationPolicy
{
    public function __construct(private ActiveConnectionHolderInterface $connectionHolder) {}

    public function preservesIdentification(): bool
    {
        return $this->connectionHolder->getProtocolModule() instanceof NickChangePreservesIdentificationInterface;
    }
}
