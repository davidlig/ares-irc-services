<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\Handler\SetEntrymsgHandler;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetEntrymsgHandler::class)]
final class SetEntrymsgHandlerTest extends TestCase
{
    #[Test]
    public function entrymsgTooLongRepliesTooLong(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())
            ->method('updateEntrymsg')
            ->with(self::anything())
            ->willThrowException(new InvalidArgumentException('too long'));
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())
            ->method('reply')
            ->with('set.entrymsg.too_long', ['%max%' => (string) RegisteredChannel::ENTRYMSG_MAX_LENGTH]);

        $handler = new SetEntrymsgHandler($channelRepo);
        $handler->handle($context, $channel, str_repeat('x', 300));
    }

    #[Test]
    public function validEntrymsgUpdatesAndReplies(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEntrymsg')->with('Welcome!');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())->method('reply')->with('set.entrymsg.updated');

        $handler = new SetEntrymsgHandler($channelRepo);
        $handler->handle($context, $channel, 'Welcome!');
    }
}
