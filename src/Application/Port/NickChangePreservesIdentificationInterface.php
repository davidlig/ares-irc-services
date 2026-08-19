<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Marker interface for protocol modules where the IRCd handles authentication
 * server-side (e.g. UDB). Nick changes may preserve +r mode because the IRCd
 * validates credentials and re-authenticates during the nick change.
 *
 * When this interface is implemented by the active protocol module, the network
 * event enricher will not strip +r on nick changes, trusting the IRCd to manage
 * authentication state.
 */
interface NickChangePreservesIdentificationInterface {}
