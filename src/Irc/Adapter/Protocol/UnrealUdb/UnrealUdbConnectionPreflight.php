<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbOfflineTakeoverInterface;
use App\Irc\Application\Connect\ConnectionPreflightResult;
use App\Irc\Application\Connect\ProtocolConnectionPreflightInterface;
use Throwable;

use function sprintf;

final readonly class UnrealUdbConnectionPreflight implements ProtocolConnectionPreflightInterface
{
    /** Deterministic marker (sha256 of 'ares-fresh-seed') for the fresh SQL-seeded bootstrap. */
    private const string FRESH_SEED_FINGERPRINT = '43566f5c8bf11593153f78572a667c7ba7705b6b33bf0c3159273fc3661f73b3';

    public function __construct(
        private UdbAuthorityStateRepositoryInterface $authority,
        private UdbOfflineTakeoverInterface $takeover,
        private string $directory,
        private bool $bootstrapFromPeer = false,
    ) {}

    public function prepare(): ConnectionPreflightResult
    {
        if ($this->authority->isApproved()) {
            return new ConnectionPreflightResult(true);
        }

        if ($this->bootstrapFromPeer) {
            return new ConnectionPreflightResult(
                true,
                'UDB bootstrap from peer enabled: the dataset will be collected from the IRCd during the first session.',
            );
        }

        if ('' === $this->directory) {
            return $this->approveFreshStore();
        }

        return $this->takeOverOfflineStore();
    }

    private function approveFreshStore(): ConnectionPreflightResult
    {
        try {
            $this->authority->approve(self::FRESH_SEED_FINGERPRINT);
        } catch (Throwable $exception) {
            return new ConnectionPreflightResult(false, sprintf('UDB fresh bootstrap failed: %s', $exception->getMessage()));
        }

        return new ConnectionPreflightResult(
            true,
            'UDB fresh bootstrap approved: blocks seed from SQL on the first link (no offline dataset configured).',
        );
    }

    private function takeOverOfflineStore(): ConnectionPreflightResult
    {
        try {
            $fingerprint = $this->takeover->takeover($this->directory);
        } catch (Throwable $exception) {
            return new ConnectionPreflightResult(false, sprintf('Automatic udb:takeover failed: %s', $exception->getMessage()));
        }

        return new ConnectionPreflightResult(
            true,
            sprintf('Automatic udb:takeover approved the dataset (fingerprint %s).', $fingerprint),
        );
    }
}
