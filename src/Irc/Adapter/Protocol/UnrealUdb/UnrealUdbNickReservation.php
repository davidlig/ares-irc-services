<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Application\Port\In\ManagedServiceNickReservationLookup;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use RuntimeException;

use function array_values;
use function count;
use function explode;
use function sprintf;
use function strcasecmp;
use function strtolower;

/**
 * UnrealUdb nick reservation via authoritative N-block forbid records.
 *
 * A nickname is reserved by inserting N::<nick>::forbid: the UDB module
 * rejects the nickname for regular users while U-lined servers (the
 * services) can still introduce it. No TKL/SQLINE command is emitted.
 *
 * The S::nickserv / S::chanserv / S::ipserv masks are still published for the
 * configured service nicks because the module resolves the notice source
 * through them (nick!ident@host form). Since NickServ handles both nicknames
 * and vhosts, it maps to both S::nickserv and S::ipserv; ChanServ maps to
 * S::chanserv.
 */
final readonly class UnrealUdbNickReservation implements ServiceNickReservationInterface, ManagedServiceNickReservationLookup
{
    private const string BLOCK = 'N';

    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
        private UdbRecordRepositoryInterface $records,
        private string $nickservNick = 'NickServ',
        private string $nickservIdent = 'NickServ',
        private string $chanservNick = 'ChanServ',
        private string $chanservIdent = 'ChanServ',
        private string $servicesVhost = 'services.davidlig.net',
    ) {}

    public function findManagedServiceNicks(string $reason): array
    {
        $managed = [];
        foreach ($this->records->recordsByBlock(self::BLOCK) as $path => $value) {
            if ($reason !== $value) {
                continue;
            }

            $components = explode('::', $path);
            if (2 !== count($components) || 0 !== strcasecmp($components[1], 'forbid')) {
                continue;
            }

            $nick = UdbPathCodec::decodeComponent($components[0]);
            if (null !== $nick) {
                $managed[strtolower($nick)] = $nick;
            }
        }

        return array_values($managed);
    }

    public function reserveNick(string $nick, string $reason): void
    {
        if (!$this->recordWriter->insert(self::BLOCK, sprintf('%s::forbid', $nick), $reason)) {
            throw new RuntimeException('Could not reserve service nickname in UDB.');
        }

        if (0 === strcasecmp($nick, $this->nickservNick)) {
            $mask = sprintf('%s!%s@%s', $nick, $this->nickservIdent, $this->servicesVhost);
            if (!$this->recordWriter->insert('S', 'nickserv', $mask)
                || !$this->recordWriter->insert('S', 'ipserv', $mask)
            ) {
                throw new RuntimeException('Could not publish NickServ identity in UDB.');
            }

            return;
        }

        if (0 === strcasecmp($nick, $this->chanservNick)) {
            $mask = sprintf('%s!%s@%s', $nick, $this->chanservIdent, $this->servicesVhost);
            if (!$this->recordWriter->insert('S', 'chanserv', $mask)) {
                throw new RuntimeException('Could not publish ChanServ identity in UDB.');
            }
        }
    }

    public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void
    {
        // N::forbid has no expiry and the temporary pseudo-client callers never
        // release their nick, so a timed reservation is not persisted.
    }

    public function releaseNick(string $nick): void
    {
        if (!$this->recordWriter->delete(self::BLOCK, sprintf('%s::forbid', $nick))) {
            throw new RuntimeException('Could not release service nickname in UDB.');
        }

        if (0 === strcasecmp($nick, $this->nickservNick)) {
            if (!$this->recordWriter->delete('S', 'nickserv')
                || !$this->recordWriter->delete('S', 'ipserv')
            ) {
                throw new RuntimeException('Could not remove NickServ identity from UDB.');
            }

            return;
        }

        if (0 === strcasecmp($nick, $this->chanservNick)) {
            if (!$this->recordWriter->delete('S', 'chanserv')) {
                throw new RuntimeException('Could not remove ChanServ identity from UDB.');
            }
        }
    }
}
