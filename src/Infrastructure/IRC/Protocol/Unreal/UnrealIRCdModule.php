<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\ServiceNickReservationInterface;
use App\Application\Port\UserModeSupportInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleInterface;

/**
 * UnrealIRCd protocol module: handler, service actions, introduction formatter, channel mode support, nick reservation.
 */
final readonly class UnrealIRCdModule implements ProtocolRuntimeModuleInterface
{
    public const string PROTOCOL_NAME = 'unreal';

    public function __construct(
        private UnrealIRCdProtocolHandler $handler,
        private UnrealIRCdProtocolServiceActions $serviceActions,
        private UnrealIRCdServiceIntroductionFormatter $introductionFormatter,
        private UnrealIRCdChannelModeSupport $channelModeSupport,
        private UnrealIRCdUserModeSupport $userModeSupport,
        private UnrealIRCdNickReservation $nickReservation,
    ) {}

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

    public function getChannelModeSupport(): ChannelModeSupportInterface
    {
        return $this->channelModeSupport;
    }

    public function getNickReservation(): ServiceNickReservationInterface
    {
        return $this->nickReservation;
    }

    public function getUserModeSupport(): UserModeSupportInterface
    {
        return $this->userModeSupport;
    }
}
