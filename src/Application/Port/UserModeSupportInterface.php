<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Exposes IRCOp-only user mode support for the active IRCd (Unreal, InspIRCd, etc.).
 *
 * Used by OperServ for:
 * - Validating user modes that can be assigned to IRCOp roles
 * - Each protocol module provides an implementation with modes from their docs.
 *
 * @see https://www.unrealircd.org/docs/User_modes
 * @see https://docs.inspircd.org/4/user-modes/
 */
interface UserModeSupportInterface
{
    /**
     * Returns IRCOp-only user mode letters that can be set via services.
     * Does NOT include 'o' (set by IRCd on /OPER), 'r' (registered, set by services on identify),
     * 'S' (services bot), or other system-reserved modes.
     *
     * @return list<string> Mode letters (case-sensitive), e.g. ['H', 'q', 's', 'W']
     */
    public function getIrcOpUserModes(): array;
}
