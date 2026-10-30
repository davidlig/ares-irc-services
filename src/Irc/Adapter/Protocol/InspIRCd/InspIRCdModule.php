<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\InspIRCd;

use App\Irc\Adapter\Protocol\ChannelBanMatcher;
use App\Irc\Adapter\Protocol\ChannelBanMatcherProvider;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;

use function in_array;

/**
 * InspIRCd protocol module: handler, service actions, mode support, and nick reservation.
 *
 * The channelModeSupport property is mutable: it starts with the factory default
 * (full InspIRCd docs profile) and is updated once the remote CAPAB is parsed,
 * replacing it with an instance that reflects the actual modes the remote IRCd supports.
 */
final class InspIRCdModule implements ProtocolRuntimeModuleInterface, ChannelBanMatcherProvider
{
    public const string PROTOCOL_NAME = 'inspircd';

    private InspIRCdChannelModeSupport $channelModeSupport;

    private string $banCaseMapping = 'ascii';

    private int $maxNickLength = 30;

    public function __construct(
        private readonly InspIRCdProtocolHandler $handler,
        private readonly InspIRCdProtocolServiceActions $serviceActions,
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

    public function getMaxNickLength(): int
    {
        return $this->maxNickLength;
    }

    public function updateMaxNickLength(int $maxNickLength): void
    {
        $this->maxNickLength = $maxNickLength;
    }

    /**
     * Replace the channel mode support with an updated instance built from
     * the remote server's CAPAB CHANMODES payload.
     */
    public function updateChannelModeSupport(InspIRCdChannelModeSupport $support): void
    {
        $this->channelModeSupport = $support;
    }

    public function updateBanCaseMapping(string $caseMapping): void
    {
        $this->banCaseMapping = $caseMapping;
    }

    public function getChannelBanMatcher(): ?ChannelBanMatcher
    {
        if (!in_array($this->banCaseMapping, ['ascii', 'rfc1459', 'strict-rfc1459'], true)) {
            return null;
        }

        return new InspIRCdChannelBanMatcher($this->banCaseMapping);
    }
}
