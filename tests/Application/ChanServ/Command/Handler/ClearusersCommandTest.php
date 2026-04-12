<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\ClearusersCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ClearusersCommand::class)]
final class ClearusersCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsClearusers(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('CLEARUSERS', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('clearusers.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('clearusers.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsExpected(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(73, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('clearusers.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsClearusersPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::CLEARUSERS, $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function usesLevelFounderReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->usesLevelFounder());
    }

    #[Test]
    public function executeWithNullSenderDoesNothing(): void
    {
        $messages = [];
        $context = $this->createContext(null, ['#test'], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithInvalidChannelRepliesInvalidChannel(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $context = $this->createContext($sender, ['invalidchan'], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithNonRegisteredChannelRepliesNotRegistered(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $context = $this->createContext($sender, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd = new ClearusersCommand($channelRepository, $this->createStub(ChanServNotifierInterface::class));
        $cmd->execute($context);

        self::assertContains('error.channel_not_registered', $messages);
    }

    #[Test]
    public function executeWithChannelNotOnNetworkRepliesNotOnNetwork(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $context = $this->createContext(
            $sender,
            ['#test'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
        );

        $cmd = new ClearusersCommand($channelRepository, $this->createStub(ChanServNotifierInterface::class));
        $cmd->execute($context);

        self::assertContains('clearusers.not_on_network', $messages);
    }

    #[Test]
    public function executeWithEmptyChannelRepliesEmpty(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelView = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 0,
            members: [],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $context = $this->createContext(
            $sender,
            ['#test'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
        );

        $cmd = new ClearusersCommand($channelRepository, $this->createStub(ChanServNotifierInterface::class));
        $cmd->execute($context);

        self::assertContains('clearusers.empty', $messages);
    }

    #[Test]
    public function executeWithMembersKicksAllAndRepliesSuccess(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelView = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 3,
            members: [
                ['uid' => 'UID1', 'roleLetter' => 'o'],
                ['uid' => 'UID2', 'roleLetter' => 'v'],
                ['uid' => 'UID3', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $kickedUsers = [];
        $kickedUsers = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channelName, string $uid, string $reason) use (&$kickedUsers): void {
            $kickedUsers[] = ['channel' => $channelName, 'uid' => $uid, 'reason' => $reason];
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $context = $this->createContext(
            $sender,
            ['#test'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
            notifier: $notifier,
        );

        $cmd = new ClearusersCommand($channelRepository, $notifier);
        $cmd->execute($context);

        self::assertContains('clearusers.success', $messages);
        self::assertCount(3, $kickedUsers);
        self::assertSame('#test', $kickedUsers[0]['channel']);
        self::assertSame('UID1', $kickedUsers[0]['uid']);
        self::assertSame('UID2', $kickedUsers[1]['uid']);
        self::assertSame('UID3', $kickedUsers[2]['uid']);

        foreach ($kickedUsers as $kick) {
            self::assertSame('clearusers.default_reason', $kick['reason']);
        }
    }

    #[Test]
    public function executeWithCustomReasonUsesCustomReason(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelView = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $kickedUsers = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channelName, string $uid, string $reason) use (&$kickedUsers): void {
            $kickedUsers[] = ['channel' => $channelName, 'uid' => $uid, 'reason' => $reason];
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $context = $this->createContext(
            $sender,
            ['#test', 'flooding'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
            notifier: $notifier,
        );

        $cmd = new ClearusersCommand($channelRepository, $notifier);
        $cmd->execute($context);

        self::assertContains('clearusers.success', $messages);
        self::assertCount(1, $kickedUsers);
        self::assertSame('flooding', $kickedUsers[0]['reason']);
    }

    #[Test]
    public function executeWithMultiWordReasonUsesFullReason(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelView = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 2,
            members: [
                ['uid' => 'UID1', 'roleLetter' => 'o'],
                ['uid' => 'UID2', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $kickedUsers = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channelName, string $uid, string $reason) use (&$kickedUsers): void {
            $kickedUsers[] = ['uid' => $uid, 'reason' => $reason];
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $context = $this->createContext(
            $sender,
            ['#test', 'mass', 'flooding', 'detected'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
            notifier: $notifier,
        );

        $cmd = new ClearusersCommand($channelRepository, $notifier);
        $cmd->execute($context);

        self::assertCount(2, $kickedUsers);
        self::assertSame('mass flooding detected', $kickedUsers[0]['reason']);
        self::assertSame('mass flooding detected', $kickedUsers[1]['reason']);
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $messages = [];

        $context = $this->createContext($this->createSender(), ['#test'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterExecute(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelView = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 2,
            members: [
                ['uid' => 'UID1', 'roleLetter' => 'o'],
                ['uid' => 'UID2', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('kickFromChannel')->willReturnCallback(static function (): void {});
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $context = $this->createContext(
            $sender,
            ['#test', 'spam'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
            notifier: $notifier,
        );

        $cmd = new ClearusersCommand($channelRepository, $notifier);
        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('spam', $auditData->reason);
        self::assertSame(['kicked_count' => 2], $auditData->extra);
    }

    #[Test]
    public function getAuditDataWithNoReasonHasNullReason(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 1);
        $messages = [];

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $channelView = new ChannelView(
            name: '#test',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('kickFromChannel')->willReturnCallback(static function (): void {});
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $context = $this->createContext(
            $sender,
            ['#test'],
            $messages,
            channelRepository: $channelRepository,
            channelLookup: $channelLookup,
            notifier: $notifier,
        );

        $cmd = new ClearusersCommand($channelRepository, $notifier);
        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertNull($auditData->reason);
        self::assertSame(['kicked_count' => 1], $auditData->extra);
    }

    private function createCommand(): ClearusersCommand
    {
        return new ClearusersCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanServNotifierInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    private function createChannelWithId(string $channelName, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($channelName, 1, 'Test channel');

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, $id);

        return $channel;
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
        ?ChannelLookupPort $channelLookup = null,
        ?ChanServNotifierInterface $notifier = null,
    ): ChanServContext {
        if (null === $notifier) {
            $notifier = $this->createStub(ChanServNotifierInterface::class);
            $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$messages): void {
                $messages[] = $message;
            });
            $notifier->method('getNick')->willReturn('ChanServ');
            $notifier->method('getServiceKey')->willReturn('chanserv');
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $channelModeSupport = $this->createStub(ChannelModeSupportInterface::class);

        return new ChanServContext(
            $sender,
            null,
            'CLEARUSERS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            $channelModeSupport,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('chanserv');
        $provider->method('getNickname')->willReturn('ChanServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
