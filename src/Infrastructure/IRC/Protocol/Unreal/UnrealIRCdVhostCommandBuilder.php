<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\VhostCommandBuilderInterface;

use function sprintf;

final readonly class UnrealIRCdVhostCommandBuilder implements VhostCommandBuilderInterface
{
    public function getSetVhostLine(string $serverSid, string $targetUid, string $vhost): string
    {
        $trailing = (str_contains($vhost, ' ')) ? ' :' . $vhost : ' ' . $vhost;

        return sprintf(':%s CHGHOST %s%s', $serverSid, $targetUid, $trailing);
    }

    public function getClearVhostLine(string $serverSid, string $targetUid): string
    {
        return sprintf(':%s SVS2MODE %s -t', $serverSid, $targetUid);
    }
}
