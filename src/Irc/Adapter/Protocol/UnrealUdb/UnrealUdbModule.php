<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptorInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;

/**
 * UnrealUdb protocol module: handler, service actions, mode support, nick reservation, and RAW interception.
 */
final readonly class UnrealUdbModule implements ProtocolRuntimeModuleInterface, NickChangePreservesIdentificationInterface, RawCommandInterceptorInterface
{
    public const string PROTOCOL_NAME = 'unrealudb';

    public function __construct(
        private UnrealUdbProtocolHandler $handler,
        private UnrealUdbProtocolServiceActions $serviceActions,
        private UnrealUdbChannelModeSupport $channelModeSupport,
        private UnrealUdbUserModeSupport $userModeSupport,
        private UnrealUdbNickReservation $nickReservation,
        private UnrealUdbRawCommandInterceptor $rawCommandInterceptor,
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

    public function intercept(array $arguments): RawCommandInterception
    {
        return $this->rawCommandInterceptor->intercept($arguments);
    }
}
