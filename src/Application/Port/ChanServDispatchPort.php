<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port for dispatching a ChanServ command. Implemented by ChanServService.
 * Allows Infrastructure (e.g. ChanServCommandListener) to be tested with a mock.
 */
interface ChanServDispatchPort
{
    /**
     * @param string     $rawText Full text of the PRIVMSG (e.g. "REGISTER #channel desc")
     * @param SenderView $sender  The user who sent the message (from NetworkUserLookupPort)
     */
    public function dispatch(string $rawText, SenderView $sender): void;
}
