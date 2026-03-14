<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\Handler\SetEmailHandler;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetEmailHandler::class)]
final class SetEmailHandlerTest extends TestCase
{
    #[Test]
    public function invalidEmailRepliesInvalid(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())->method('reply')->with('set.email.invalid');

        $handler = new SetEmailHandler($channelRepo);
        $handler->handle($context, $channel, 'not-an-email');
    }

    #[Test]
    public function validEmailUpdatesAndRepliesUpdated(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEmail')->with('chan@example.com');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())->method('reply')->with('set.email.updated');

        $handler = new SetEmailHandler($channelRepo);
        $handler->handle($context, $channel, '  chan@example.com  ');
    }

    #[Test]
    public function emptyValueClearsEmailAndRepliesCleared(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEmail')->with(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())->method('reply')->with('set.email.cleared');

        $handler = new SetEmailHandler($channelRepo);
        $handler->handle($context, $channel, '   ');
    }
}
