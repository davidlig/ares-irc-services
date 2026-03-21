<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\ServiceNickReservationInterface;
use App\Application\Port\VhostCommandBuilderInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

/**
 * UnrealIRCd protocol module: handler, service actions, introduction formatter, vhost builder, channel mode support, nick reservation.
 */
final readonly class UnrealIRCdModule implements ProtocolModuleInterface
{
    public const string PROTOCOL_NAME = 'unreal';

    public function __construct(
        private readonly UnrealIRCdProtocolHandler $handler,
        private readonly UnrealIRCdProtocolServiceActions $serviceActions,
        private readonly UnrealIRCdServiceIntroductionFormatter $introductionFormatter,
        private readonly UnrealIRCdVhostCommandBuilder $vhostCommandBuilder,
        private readonly UnrealIRCdChannelModeSupport $channelModeSupport,
        private readonly UnrealIRCdNickReservation $nickReservation,
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

    public function getChannelModeSupport(): ChannelModeSupportInterface
    {
        return $this->channelModeSupport;
    }

    public function getNickReservation(): ServiceNickReservationInterface
    {
        return $this->nickReservation;
    }
}
