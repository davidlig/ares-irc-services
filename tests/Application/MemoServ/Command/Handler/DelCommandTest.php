<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\Handler\DelCommand;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DelCommand::class)]
final class DelCommandTest extends TestCase
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
            'DEL',
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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelCommand($channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['1'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replySyntaxErrorWhenIndexNotDigit(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelCommand($channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['x'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function replyNotFoundWhenMemoDoesNotExist(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelCommand($channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['99'], $notifier, $translator));

        self::assertSame(['del.not_found'], $messages);
    }

    #[Test]
    public function successDeletesNickMemoAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memo = new Memo(1, null, 2, 'Msg');
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn($memo);
        $memoRepo->expects(self::once())->method('delete')->with($memo);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DelCommand($channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['1'], $notifier, $translator));

        self::assertSame(['del.deleted'], $messages);
    }
}
