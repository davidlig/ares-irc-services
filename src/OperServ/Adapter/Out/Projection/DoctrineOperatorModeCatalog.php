<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\OperServ\Application\Port\Out\OperatorModeCatalog;

final readonly class DoctrineOperatorModeCatalog implements OperatorModeCatalog
{
    public function __construct(private ActiveProtocolModuleHolderInterface $connection) {}

    public function available(): ?array
    {
        $module = $this->connection->getProtocolModule();

        return null === $module ? null : $module->getUserModeSupport()->getIrcOpUserModes();
    }
}
