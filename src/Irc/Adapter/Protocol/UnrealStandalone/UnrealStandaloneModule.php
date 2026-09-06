<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealStandalone;

use App\Application\Port\ChannelModeSupportInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceIntroductionFormatterInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;

/**
 * UnrealIRCd protocol module: handler, service actions, introduction formatter, channel mode support, nick reservation.
 */
final readonly class UnrealStandaloneModule implements ProtocolRuntimeModuleInterface
{
    public const string PROTOCOL_NAME = 'unreal';

    public function __construct(
        private UnrealStandaloneProtocolHandler $handler,
        private UnrealStandaloneProtocolServiceActions $serviceActions,
        private UnrealStandaloneServiceIntroductionFormatter $introductionFormatter,
        private UnrealStandaloneChannelModeSupport $channelModeSupport,
        private UnrealStandaloneUserModeSupport $userModeSupport,
        private UnrealStandaloneNickReservation $nickReservation,
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
