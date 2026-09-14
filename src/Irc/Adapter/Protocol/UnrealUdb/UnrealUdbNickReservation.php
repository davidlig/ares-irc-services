<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;

use function sprintf;
use function strcasecmp;

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
final readonly class UnrealUdbNickReservation implements ServiceNickReservationInterface
{
    private const string BLOCK = 'N';

    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
        private string $nickservNick = 'NickServ',
        private string $nickservIdent = 'NickServ',
        private string $chanservNick = 'ChanServ',
        private string $chanservIdent = 'ChanServ',
        private string $servicesVhost = 'services.davidlig.net',
    ) {}

    public function reserveNick(string $nick, string $reason): void
    {
        $this->recordWriter->insert(self::BLOCK, sprintf('%s::forbid', $nick), $reason);

        if (0 === strcasecmp($nick, $this->nickservNick)) {
            $mask = sprintf('%s!%s@%s', $nick, $this->nickservIdent, $this->servicesVhost);
            $this->recordWriter->insert('S', 'nickserv', $mask);
            $this->recordWriter->insert('S', 'ipserv', $mask);

            return;
        }

        if (0 === strcasecmp($nick, $this->chanservNick)) {
            $mask = sprintf('%s!%s@%s', $nick, $this->chanservIdent, $this->servicesVhost);
            $this->recordWriter->insert('S', 'chanserv', $mask);
        }
    }

    public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void
    {
        // N::forbid has no expiry and the temporary pseudo-client callers never
        // release their nick, so a timed reservation is not persisted.
    }

    public function releaseNick(string $nick): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('%s::forbid', $nick));

        if (0 === strcasecmp($nick, $this->nickservNick)) {
            $this->recordWriter->delete('S', 'nickserv');
            $this->recordWriter->delete('S', 'ipserv');

            return;
        }

        if (0 === strcasecmp($nick, $this->chanservNick)) {
            $this->recordWriter->delete('S', 'chanserv');
        }
    }
}
