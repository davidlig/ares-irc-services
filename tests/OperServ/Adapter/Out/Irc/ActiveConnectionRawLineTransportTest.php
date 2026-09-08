<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\OperServ\Adapter\Out\Irc\ActiveConnectionRawLineTransport;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveConnectionRawLineTransport::class)]
final class ActiveConnectionRawLineTransportTest extends TestCase
{
    #[Test]
    public function delegatesConnectionStateAndRawLineWrites(): void
    {
        $connection = $this->createMock(ActiveConnectionHolderInterface::class);
        $connection->expects(self::once())->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with(':001 NOTICE Alice :message');
        $transport = new ActiveConnectionRawLineTransport($connection);

        self::assertTrue($transport->isConnected());
        $transport->send(':001 NOTICE Alice :message');
    }
}
