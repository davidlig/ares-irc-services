<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ServiceIntroductionFormatterInterface;

use function sprintf;

/**
 * UnrealIRCd: introduce a service pseudo-client with a UID line.
 * Format: :serverSid UID nickname hopcount timestamp username hostname uid servicestamp usermodes virtualhost cloakedhost ip :gecos.
 */
final readonly class UnrealIRCdServiceIntroductionFormatter implements ServiceIntroductionFormatterInterface
{
    public function formatIntroduction(
        string $serverSid,
        string $nick,
        string $ident,
        string $host,
        string $uid,
        string $realname,
    ): string {
        $ts = time();

        return sprintf(
            ':%s UID %s 1 %d %s %s %s 0 +Sio * * * :%s',
            $serverSid,
            $nick,
            $ts,
            $ident,
            $host,
            $uid,
            $realname,
        );
    }
}
