<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ServiceIntroductionFormatterInterface;

use function sprintf;

/**
 * InspIRCd SpanTree: introduce a service pseudo-client with a UID line.
 * Format (1206+): :serverSid UID uuid ts nick real_host displayed_host real_user displayed_user ip connect_time modes :realname.
 */
final readonly class InspIRCdServiceIntroductionFormatter implements ServiceIntroductionFormatterInterface
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
            ':%s UID %s %d %s %s %s %s %s * %d +Sio :%s',
            $serverSid,
            $uid,
            $ts,
            $nick,
            $host,
            $host,
            $ident,
            $ident,
            $ts,
            $realname,
        );
    }
}
