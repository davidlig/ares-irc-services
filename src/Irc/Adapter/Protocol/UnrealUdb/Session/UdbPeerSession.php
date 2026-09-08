<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;

use function in_array;
use function preg_match;
use function strcasecmp;

/** Owns direct-peer identity and the remote UDB instance advertisement. */
final class UdbPeerSession
{
    private ?string $ownName = null;

    private ?string $remoteSid = null;

    private ?string $remoteServerName = null;

    private ?string $remoteEpoch = null;

    private bool $authorized = false;

    public function setOwnName(string $ownName): void
    {
        $this->ownName = $ownName;
    }

    public function ownName(): ?string
    {
        return $this->ownName;
    }

    public function captureRemote(string $sid, string $serverName): void
    {
        $this->remoteSid = $sid;
        $this->remoteServerName = $serverName;
    }

    public function remoteSid(): ?string
    {
        return $this->remoteSid;
    }

    public function remoteServerName(): ?string
    {
        return $this->remoteServerName;
    }

    public function isDirect(UdbFrame $frame, string $localSid): bool
    {
        return null !== $this->remoteSid
            && $frame->sourceSid === $this->remoteSid
            && $frame->target === $localSid;
    }

    public function observeAdvertisement(UdbFrame $frame, bool $bootstrap): UdbPeerAdvertisementChange
    {
        if (null === $frame->propagator
            || null === $frame->epoch
            || 1 !== preg_match('/^[0-9a-f]{16}$/D', $frame->epoch)
            || !in_array($frame->capabilities, [['OCL'], ['OCL', 'OCLG']], true)
        ) {
            return UdbPeerAdvertisementChange::Invalid;
        }

        $changed = null !== $this->remoteEpoch && $this->remoteEpoch !== $frame->epoch;
        $this->remoteEpoch = $frame->epoch;
        $this->authorized = $bootstrap
            || (null !== $this->ownName && '' !== $this->ownName && 0 === strcasecmp($frame->propagator, $this->ownName));

        return $changed ? UdbPeerAdvertisementChange::NewInstance : UdbPeerAdvertisementChange::SameInstance;
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    public function reset(): void
    {
        $this->remoteSid = null;
        $this->remoteServerName = null;
        $this->remoteEpoch = null;
        $this->authorized = false;
    }
}
