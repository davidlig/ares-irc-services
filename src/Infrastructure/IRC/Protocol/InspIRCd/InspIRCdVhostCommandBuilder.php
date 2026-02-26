<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\VhostCommandBuilderInterface;

use function sprintf;
use function str_contains;

/**
 * InspIRCd vhost commands per server protocol docs (docs.inspircd.org/server/messages/fhost).
 * Uses FHOST: prefix is the UID of the user whose host is changed; param is the new displayed host.
 * InspIRCd does not document SVS2MODE for user modes (only SVSCMODE for channel list modes).
 */
final readonly class InspIRCdVhostCommandBuilder implements VhostCommandBuilderInterface
{
    public function getSetVhostLine(string $serverSid, string $targetUid, string $vhost): string
    {
        $arg = str_contains($vhost, ' ') ? ' :' . $vhost : ' ' . $vhost;

        return sprintf(':%s FHOST%s', $targetUid, $arg);
    }

    /**
     * Clear displayed vhost (restore real/cloaked host).
     * FHOST with "*" as displayed host resets to real; if a server requires the literal real
     * host instead, the port could be extended to accept an optional realHost parameter.
     */
    public function getClearVhostLine(string $serverSid, string $targetUid): string
    {
        return sprintf(':%s FHOST *', $targetUid);
    }
}
