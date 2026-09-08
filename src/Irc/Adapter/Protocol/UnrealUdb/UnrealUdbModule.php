<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceIntroductionFormatterInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;

/**
 * UnrealUdb protocol module: handler, service actions, introduction formatter, channel mode support, nick reservation.
 */
final readonly class UnrealUdbModule implements ProtocolRuntimeModuleInterface, NickChangePreservesIdentificationInterface, UdbRawCommandHandlerInterface
{
    public const string PROTOCOL_NAME = 'unrealudb';

    public function __construct(
        private UnrealUdbProtocolHandler $handler,
        private UnrealUdbProtocolServiceActions $serviceActions,
        private UnrealUdbServiceIntroductionFormatter $introductionFormatter,
        private UnrealUdbChannelModeSupport $channelModeSupport,
        private UnrealUdbUserModeSupport $userModeSupport,
        private UnrealUdbNickReservation $nickReservation,
        private UdbRawCommandHandlerInterface $rawCommandHandler,
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

    public function ins(string $blockPath, string $value): UdbRawCommandResult
    {
        return $this->rawCommandHandler->ins($blockPath, $value);
    }

    public function del(string $blockPath): UdbRawCommandResult
    {
        return $this->rawCommandHandler->del($blockPath);
    }
}
