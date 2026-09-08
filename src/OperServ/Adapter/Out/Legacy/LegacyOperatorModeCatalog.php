<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\OperServ\Application\Port\Out\OperatorModeCatalog;

final readonly class LegacyOperatorModeCatalog implements OperatorModeCatalog
{
    public function __construct(private ActiveConnectionHolderInterface $connection) {}

    public function available(): ?array
    {
        $module = $this->connection->getProtocolModule();

        return null === $module ? null : $module->getUserModeSupport()->getIrcOpUserModes();
    }
}
