<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

/**
 * Bundles all protocol-specific pieces for one IRCd type (Unreal, InspIRCd, P10, etc.).
 * Each IRCd module lives in its own namespace and provides handler, formatters and actions.
 * No generic "protocol" class holds a switch over IRCd types.
 */
interface ProtocolModuleInterface
{
    public function getProtocolName(): string;

    public function getHandler(): ProtocolHandlerInterface;

    public function getServiceActions(): ProtocolServiceActionsInterface;

    public function getIntroductionFormatter(): ServiceIntroductionFormatterInterface;

    public function getVhostCommandBuilder(): VhostCommandBuilderInterface;

    /** Which channel prefix modes (v, h, o, a, q) this IRCd supports. Used by ChanServ. */
    public function getChannelModeSupport(): ChannelModeSupportInterface;

    /** Which IRCOp-only user modes this IRCd supports. Used by OperServ. */
    public function getUserModeSupport(): UserModeSupportInterface;

    /**
     * Nickname reservation for services (SQLINE/QLINE).
     * Returns null if the protocol does not support reservation.
     */
    public function getNickReservation(): ?ServiceNickReservationInterface;
}
