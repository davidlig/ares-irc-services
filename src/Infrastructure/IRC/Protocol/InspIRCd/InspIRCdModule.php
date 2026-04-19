<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\ServiceNickReservationInterface;
use App\Application\Port\UserModeSupportInterface;
use App\Application\Port\VhostCommandBuilderInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

/**
 * InspIRCd protocol module: handler, service actions, introduction formatter, vhost builder, channel mode support, nick reservation.
 *
 * The channelModeSupport property is mutable: it starts with the factory default
 * (full InspIRCd docs profile) and is updated once the remote CAPAB is parsed,
 * replacing it with an instance that reflects the actual modes the remote IRCd supports.
 */
final class InspIRCdModule implements ProtocolModuleInterface
{
    public const string PROTOCOL_NAME = 'inspircd';

    private InspIRCdChannelModeSupport $channelModeSupport;

    public function __construct(
        private readonly InspIRCdProtocolHandler $handler,
        private readonly InspIRCdProtocolServiceActions $serviceActions,
        private readonly InspIRCdServiceIntroductionFormatter $introductionFormatter,
        private readonly InspIRCdVhostCommandBuilder $vhostCommandBuilder,
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
