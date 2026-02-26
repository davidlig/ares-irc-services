<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol;

use App\Application\Port\VhostCommandBuilderInterface;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdVhostCommandBuilder;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdVhostCommandBuilder;

final readonly class ProtocolVhostCommandBuilder implements VhostCommandBuilderInterface
{
    public function __construct(
        private readonly string $protocol,
        private readonly UnrealIRCdVhostCommandBuilder $unrealBuilder,
        private readonly InspIRCdVhostCommandBuilder $inspircdBuilder,
    ) {
    }

    public function getSetVhostLine(string $serverSid, string $targetUid, string $vhost): string
    {
        return $this->builder()->getSetVhostLine($serverSid, $targetUid, $vhost);
    }

    public function getClearVhostLine(string $serverSid, string $targetUid): string
    {
        return $this->builder()->getClearVhostLine($serverSid, $targetUid);
    }

    private function builder(): VhostCommandBuilderInterface
    {
        return match ($this->protocol) {
            'inspircd' => $this->inspircdBuilder,
            default => $this->unrealBuilder,
        };
    }
}
