<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\ServiceNickReservationInterface;
use App\Application\Port\UserModeSupportInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleInterface;

/**
 * UnrealUdb protocol module: handler, service actions, introduction formatter, channel mode support, nick reservation.
 */
final readonly class UnrealUdbModule implements ProtocolRuntimeModuleInterface, NickChangePreservesIdentificationInterface
{
    public const string PROTOCOL_NAME = 'unrealudb';

    public function __construct(
        private readonly UnrealUdbProtocolHandler $handler,
        private readonly UnrealUdbProtocolServiceActions $serviceActions,
        private readonly UnrealUdbServiceIntroductionFormatter $introductionFormatter,
        private readonly UnrealUdbChannelModeSupport $channelModeSupport,
        private readonly UnrealUdbUserModeSupport $userModeSupport,
        private readonly UnrealUdbNickReservation $nickReservation,
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
