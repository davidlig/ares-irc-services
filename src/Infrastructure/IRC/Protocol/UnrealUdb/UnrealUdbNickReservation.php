<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;

use function sprintf;
use function strcasecmp;

/**
 * UnrealUdb nick reservation via UDB service identity settings in block S.
 *
 * UDB recognizes service bots through the S::nickserv / S::chanserv /
 * S::ipserv masks (nick!ident@host form). Since NickServ handles both
 * nicknames and vhosts, it maps to both S::nickserv and S::ipserv.
 * ChanServ maps to S::chanserv.
 */
final readonly class UnrealUdbNickReservation implements ServiceNickReservationInterface
{
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
        // UDB service identities are permanent; there is no timed reservation.
    }

    public function releaseNick(string $nick): void
    {
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
