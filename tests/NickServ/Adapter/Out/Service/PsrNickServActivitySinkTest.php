<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Service;

use App\NickServ\Adapter\Out\Service\PsrNickServActivitySink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PsrNickServActivitySink::class)]
final class PsrNickServActivitySinkTest extends TestCase
{
    #[Test]
    public function forwardsEachActivitySeverityToPsrLogging(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with('diagnostic');
        $logger->expects(self::once())->method('info')->with('activity');
        $logger->expects(self::once())->method('warning')->with('warning');

        $sink = new PsrNickServActivitySink($logger);
        $sink->debug('diagnostic');
        $sink->info('activity');
        $sink->warning('warning');
    }
}
