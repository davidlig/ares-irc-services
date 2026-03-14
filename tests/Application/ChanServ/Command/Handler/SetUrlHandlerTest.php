<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\Handler\SetUrlHandler;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetUrlHandler::class)]
final class SetUrlHandlerTest extends TestCase
{
    #[Test]
    public function handleUpdatesUrlAndReplies(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateUrl')->with('https://example.com');
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with($channel);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())->method('reply')->with('set.url.updated');

        $handler = new SetUrlHandler($repo);
        $handler->handle($context, $channel, '  https://example.com  ');
    }

    #[Test]
    public function handleClearsUrlWhenValueEmptyAfterTrim(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateUrl')->with(null);
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with($channel);
        $context = $this->createMock(ChanServContext::class);
        $context->expects(self::once())->method('reply')->with('set.url.cleared');

        $handler = new SetUrlHandler($repo);
        $handler->handle($context, $channel, '   ');
    }
}
