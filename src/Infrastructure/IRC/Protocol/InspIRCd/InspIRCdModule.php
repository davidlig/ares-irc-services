<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\VhostCommandBuilderInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

/**
 * InspIRCd protocol module: handler, service actions, introduction formatter, vhost builder.
 */
final readonly class InspIRCdModule implements ProtocolModuleInterface
{
    public const string PROTOCOL_NAME = 'inspircd';

    public function __construct(
        private readonly InspIRCdProtocolHandler $handler,
        private readonly InspIRCdProtocolServiceActions $serviceActions,
        private readonly InspIRCdServiceIntroductionFormatter $introductionFormatter,
        private readonly InspIRCdVhostCommandBuilder $vhostCommandBuilder,
    ) {
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getHandler(): ProtocolHandlerInterface
    {
        return $this->handler;
    }

    public function getServiceActions(): ProtocolServiceActionsInterface
    {
        return $this->serviceActions;
    }

    public function getIntroductionFormatter(): ServiceIntroductionFormatterInterface
    {
        return $this->introductionFormatter;
    }

    public function getVhostCommandBuilder(): VhostCommandBuilderInterface
    {
        return $this->vhostCommandBuilder;
    }
}
