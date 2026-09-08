<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\Out\Service\PsrNickDropCleanupActivitySink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PsrNickDropCleanupActivitySink::class)]
final class PsrNickDropCleanupActivitySinkTest extends TestCase
{
    #[Test]
    public function mapsSemanticActivitiesToHistoricalPsrRecords(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Channel founder transferred to successor on nick drop',
            ['channelId' => 7, 'channelName' => '#successor', 'newFounderNickId' => 88],
        );
        $logger->expects(self::once())->method('notice')->with(
            'Channel dropped due to founder nick drop with no successor',
            ['channelId' => 9, 'channelName' => '#orphan'],
        );

        $sink = new PsrNickDropCleanupActivitySink($logger);
        $sink->founderTransferred(7, '#successor', 88);
        $sink->channelDropped(9, '#orphan');
    }
}
