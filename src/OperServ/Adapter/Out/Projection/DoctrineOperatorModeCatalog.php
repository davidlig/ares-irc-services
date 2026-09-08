<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\Out\OperatorModeCatalog;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;

final readonly class DoctrineOperatorModeCatalog implements OperatorModeCatalog
{
    public function __construct(private ActiveConnectionHolderInterface $connection) {}

    public function available(): ?array
    {
        $module = $this->connection->getProtocolModule();

        return null === $module ? null : $module->getUserModeSupport()->getIrcOpUserModes();
    }
}
