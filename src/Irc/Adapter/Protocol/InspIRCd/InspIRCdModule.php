<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\InspIRCd;

use App\Application\Port\ChannelModeSupportInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceIntroductionFormatterInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;

/**
 * InspIRCd protocol module: handler, service actions, introduction formatter, channel mode support, nick reservation.
 *
 * The channelModeSupport property is mutable: it starts with the factory default
 * (full InspIRCd docs profile) and is updated once the remote CAPAB is parsed,
 * replacing it with an instance that reflects the actual modes the remote IRCd supports.
 */
final class InspIRCdModule implements ProtocolRuntimeModuleInterface
{
    public const string PROTOCOL_NAME = 'inspircd';

    private InspIRCdChannelModeSupport $channelModeSupport;

    public function __construct(
        private readonly InspIRCdProtocolHandler $handler,
        private readonly InspIRCdProtocolServiceActions $serviceActions,
        private readonly InspIRCdServiceIntroductionFormatter $introductionFormatter,
        InspIRCdChannelModeSupport $channelModeSupport,
        private readonly InspIRCdUserModeSupport $userModeSupport,
        private readonly InspIRCdNickReservation $nickReservation,
    ) {
        $this->channelModeSupport = $channelModeSupport;
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

    /**
     * Replace the channel mode support with an updated instance built from
     * the remote server's CAPAB CHANMODES payload.
     */
    public function updateChannelModeSupport(InspIRCdChannelModeSupport $support): void
    {
        $this->channelModeSupport = $support;
    }
}
