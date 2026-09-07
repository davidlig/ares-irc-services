<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\Out\Service\PsrChanServActivitySink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PsrChanServActivitySink::class)]
final class PsrChanServActivitySinkTest extends TestCase
{
    #[Test]
    public function forwardsEachActivitySeverityToPsrLogging(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with('diagnostic');
        $logger->expects(self::once())->method('info')->with('activity');
        $logger->expects(self::once())->method('warning')->with('warning');

        $sink = new PsrChanServActivitySink($logger);
        $sink->debug('diagnostic');
        $sink->info('activity');
        $sink->warning('warning');
    }
}
