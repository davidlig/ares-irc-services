<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

/**
 * Bundles all protocol-specific pieces for one IRCd type (Unreal, InspIRCd, P10, etc.).
 * Each IRCd module lives in its own namespace and provides handler, formatters and actions.
 * No generic "protocol" class holds a switch over IRCd types.
 */
interface ProtocolModuleInterface
{
    public function getProtocolName(): string;

    public function getHandler(): ProtocolHandlerInterface;

    public function getServiceActions(): ProtocolServiceActionsInterface;

    public function getIntroductionFormatter(): ServiceIntroductionFormatterInterface;

    public function getVhostCommandBuilder(): VhostCommandBuilderInterface;
}
