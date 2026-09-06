<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Application\Port\In\ServiceIntroductionFormatterInterface;

use function sprintf;

/**
 * UnrealIRCd: introduce a service pseudo-client with a UID line.
 * Format: :serverSid UID nickname hopcount timestamp username hostname uid servicestamp usermodes virtualhost cloakedhost ip :gecos.
 */
final readonly class UnrealStandaloneServiceIntroductionFormatter implements ServiceIntroductionFormatterInterface
{
    private const array SERVICE_UMODES = [
        'nickserv' => '+dIopqS',
        'chanserv' => '+dIopqS',
        'memoserv' => '+dIopqRS',
        'operserv' => '+dIopqRS',
    ];

    public function formatIntroduction(
        string $serverSid,
        string $nick,
        string $ident,
        string $host,
        string $uid,
        string $realname,
        string $serviceName,
    ): string {
        $ts = time();
        $umodes = self::SERVICE_UMODES[$serviceName] ?? '+Siod';

        return sprintf(
            ':%s UID %s 1 %d %s %s %s 0 %s * * * :%s',
            $serverSid,
            $nick,
            $ts,
            $ident,
            $host,
            $uid,
            $umodes,
            $realname,
        );
    }
}
