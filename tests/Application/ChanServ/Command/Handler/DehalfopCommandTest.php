<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\DehalfopCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DehalfopCommand::class)]
final class DehalfopCommandTest extends TestCase
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
            'DEHALFOP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new UnrealIRCdChannelModeSupport(),
        );
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DehalfopCommand($channelRepo, $nickRepo, $userLookup, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['x', 'Nick'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function successRemovesHalfopModeAndReplies(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getLevel')->willReturn(200);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip'));
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $messages = [];
        $modeCalls = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelMemberMode')->willReturnCallback(function (string $ch, string $uid, string $letter, bool $add) use (&$modeCalls): void {
            $modeCalls[] = [$ch, $uid, $letter, $add];
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new DehalfopCommand($channelRepo, $nickRepo, $userLookup, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['dehalfop.done'], $messages);
        self::assertSame(['#test', 'UID2', 'h', false], $modeCalls[0]);
    }
}
