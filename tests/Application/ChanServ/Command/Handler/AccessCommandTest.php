<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\AccessCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AccessCommand::class)]
final class AccessCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'ACCESS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
        );
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new \App\Application\ChanServ\ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['notachannel', 'LIST'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new \App\Application\ChanServ\ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, null, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new \App\Application\ChanServ\ChanServAccessHelper($accessRepo, $levelRepo);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));
    }
}
