<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** Public IRC boundary exposing the protocol module selected by Bootstrap. */
interface ActiveProtocolModuleHolderInterface extends ActiveConnectionHolderInterface
{
    public function setProtocolModule(ProtocolModuleInterface $module): void;

    public function getProtocolModule(): ?ProtocolModuleInterface;
}
