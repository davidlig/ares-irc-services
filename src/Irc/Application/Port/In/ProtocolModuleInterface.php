<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Bundles all protocol-specific pieces for one IRCd type (Unreal, InspIRCd, P10, etc.).
 * Each IRCd module lives in its own namespace and provides its handler and capabilities.
 * No generic "protocol" class holds a switch over IRCd types.
 */
interface ProtocolModuleInterface
{
    public function getProtocolName(): string;

    public function getServiceActions(): ProtocolServiceActionsInterface;

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
