<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\VhostCommandBuilderInterface;

use function sprintf;

/**
 * InspIRCd vhost commands via ENCAP CHGHOST and MODE.
 *
 * Setting vhost: ENCAP <target_sid> CHGHOST <uid> <vhost>
 * Clearing vhost: ENCAP <target_sid> CHGHOST <uid> <real_host> + MODE <uid> +x
 *
 * Inspired by Anope's SendVHostDel: first restore the real host via CHGHOST,
 * then activate cloak mode (+x) so InspIRCd recalculates and displays the cloaked host.
 *
 * Reference: https://github.com/anope/anope/blob/2.1/modules/protocol/inspircd.cpp
 */
final readonly class InspIRCdVhostCommandBuilder implements VhostCommandBuilderInterface
{
    public function getSetVhostLine(string $serverSid, string $targetUid, string $vhost): string
    {
        $targetSid = substr($targetUid, 0, 3);

        return sprintf(':%s ENCAP %s CHGHOST %s %s', $serverSid, $targetSid, $targetUid, $vhost);
    }

    public function getClearVhostLines(string $serverSid, string $targetUid, string $realHost): array
    {
        $targetSid = substr($targetUid, 0, 3);

        return [
            sprintf(':%s ENCAP %s CHGHOST %s %s', $serverSid, $targetSid, $targetUid, $realHost),
            sprintf(':%s MODE %s +x', $serverSid, $targetUid),
        ];
    }
}
