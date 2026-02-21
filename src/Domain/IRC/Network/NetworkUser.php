<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

/**
 * Represents a user currently connected to the IRC network.
 *
 * Fields sourced from the UID command:
 *   UID nickname hopcount timestamp username hostname uid servicestamp umodes virthost cloakedhost ip :gecos
 *
 * The uid, ident, hostname, cloakedhost, connectedAt and server are immutable.
 * nick, virtualHost and modes may change during the session.
 */
class NetworkUser
{
    private Nick $nick;
    private string $virtualHost;
    private string $modes;

    public function __construct(
        public readonly Uid $uid,
        Nick $nick,
        public readonly Ident $ident,
        public readonly string $hostname,
        public readonly string $cloakedHost,
        string $virtualHost,
        string $modes,
        public readonly \DateTimeImmutable $connectedAt,
        public readonly string $realName,
        public readonly string $serverSid,
        public readonly string $ipBase64,
        public readonly int $serviceStamp = 0,
    ) {
        $this->nick        = $nick;
        $this->virtualHost = $virtualHost;
        $this->modes       = $modes;
    }

    public function getNick(): Nick
    {
        return $this->nick;
    }

    public function changeNick(Nick $newNick): void
    {
        $this->nick = $newNick;
    }

    public function getVirtualHost(): string
    {
        return $this->virtualHost;
    }

    public function setVirtualHost(string $host): void
    {
        $this->virtualHost = $host;
    }

    public function getModes(): string
    {
        return $this->modes;
    }

    public function setModes(string $modes): void
    {
        $this->modes = $modes;
    }

    public function isOper(): bool
    {
        return str_contains($this->modes, 'o') || str_contains($this->modes, 'O');
    }

    public function isBot(): bool
    {
        return str_contains($this->modes, 'B');
    }

    /** Returns true when the IRCd has set the +r (registered/identified) mode. */
    public function isIdentified(): bool
    {
        return str_contains($this->modes, 'r');
    }

    /**
     * Decodes the base64-encoded binary IP to a human-readable address.
     */
    public function getIpAddress(): string
    {
        if ('*' === $this->ipBase64) {
            return '*';
        }

        $binary = base64_decode($this->ipBase64, strict: true);

        if ($binary === false) {
            return $this->ipBase64;
        }

        $ip = inet_ntop($binary);

        return $ip !== false ? $ip : $this->ipBase64;
    }

    public function getDisplayHost(): string
    {
        return '*' !== $this->virtualHost ? $this->virtualHost : $this->cloakedHost;
    }

    public function toArray(): array
    {
        return [
            'uid'         => $this->uid->value,
            'nick'        => $this->nick->value,
            'ident'       => $this->ident->value,
            'hostname'    => $this->hostname,
            'cloakedHost' => $this->cloakedHost,
            'virtualHost' => $this->virtualHost,
            'displayHost' => $this->getDisplayHost(),
            'modes'       => $this->modes,
            'realName'    => $this->realName,
            'server'      => $this->serverSid,
            'ip'          => $this->getIpAddress(),
            'connectedAt' => $this->connectedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
