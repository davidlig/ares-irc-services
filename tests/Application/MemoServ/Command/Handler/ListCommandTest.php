<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\MemoServ\Command\Handler\ListCommand;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ListCommand::class)]
final class ListCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        MemoServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): MemoServContext {
        return new MemoServContext(
            $sender,
            $senderAccount,
            'LIST',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
        );
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(\App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, null, [], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyEmptyWhenNoMemosForNick(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([]);
        $accessRepo = $this->createStub(\App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, [], $notifier, $translator));

        self::assertSame(['list.empty'], $messages);
    }

    #[Test]
    public function replyChannelNotRegisteredWhenChannelArgAndNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(\App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#unknown'], $notifier, $translator));

        self::assertSame(['list.channel_not_registered'], $messages);
    }
}
