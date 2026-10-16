<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

/**
 * Result of asking the registration owner to deregister a channel.
 *
 * CReG's own outcome: the Application layer never sees ChanServ's enum, so the adapter maps
 * ChanServ's ChannelDeregistrationOutcome onto this one.
 */
enum ChannelDropOutcome
{
    case Dropped;
    case NotRegistered;
    case Failed;
}
