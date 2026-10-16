<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

/**
 * Result of asking ChanServ to deregister a channel on behalf of another service.
 *
 * Mirrors ChannelRegistrationOutcome, but for the drop direction: CReG's DROP hands the
 * unregistration over to ChanServ through this public port, without knowing how ChanServ
 * performs it internally.
 */
enum ChannelDeregistrationOutcome
{
    case Dropped;
    case NotRegistered;
    case Failed;
}
