<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\ClearaccessCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ClearaccessCommand::class)]
final class ClearaccessCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsClearaccess(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('CLEARACCESS', $cmd->getName());
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
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('clearaccess.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('clearaccess.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyFour(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(74, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('clearaccess.short', $cmd->getShortDescKey());
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
    public function getRequiredPermissionReturnsClearaccess(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::CLEARACCESS, $cmd->getRequiredPermission());
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
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['#test'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeDoesNothingWhenSenderNull(): void
    {
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::never())->method('deleteByChannelId');
        $accessRepo->expects(self::never())->method('countByChannel');

        $context = $this->createContextWithNullSender(['#test']);

        $cmd = new ClearaccessCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $accessRepo,
        );
        $cmd->execute($context);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithInvalidChannelNameRepliesInvalidChannel(): void
    {
        $messages = [];
        $context = $this->createContext(['InvalidChannel'], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithNonexistentChannelRepliesNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $messages = [];
        $context = $this->createContext(['#test'], $messages, channelRepo: $channelRepo);

        $cmd = $this->createCommandWithRepos($channelRepo);
        $cmd->execute($context);

        self::assertContains('error.channel_not_registered', $messages);
    }

    #[Test]
    public function executeWithEmptyAccessListRepliesEmpty(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('countByChannel')->with(1)->willReturn(0);
        $accessRepo->expects(self::never())->method('deleteByChannelId');

        $messages = [];
        $context = $this->createContext(['#test'], $messages, channelRepo: $channelRepo, accessRepo: $accessRepo);

        $cmd = new ClearaccessCommand($channelRepo, $accessRepo);
        $cmd->execute($context);

        self::assertContains('clearaccess.empty', $messages);
        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeWithAccessEntriesDeletesAndRepliesSuccess(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('countByChannel')->with(1)->willReturn(5);
        $accessRepo->expects(self::once())->method('deleteByChannelId')->with(1);

        $messages = [];
        $context = $this->createContext(['#test'], $messages, channelRepo: $channelRepo, accessRepo: $accessRepo);

        $cmd = new ClearaccessCommand($channelRepo, $accessRepo);
        $cmd->execute($context);

        self::assertContains('clearaccess.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame(['count' => 5], $auditData->extra);
    }

    private function createCommand(): ClearaccessCommand
    {
        return new ClearaccessCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
        );
    }

    private function createCommandWithRepos(
        RegisteredChannelRepositoryInterface $channelRepo,
    ): ClearaccessCommand {
        return new ClearaccessCommand(
            $channelRepo,
            $this->createStub(ChannelAccessRepositoryInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'fwAAAQ==', false, true, 'SID1', 'h', 'o', '');
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
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
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepo = null,
        ?ChannelAccessRepositoryInterface $accessRepo = null,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $targetUid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            $this->createSender(),
            $this->createNickWithId('OperUser', 2),
            'CLEARACCESS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createContextWithNullSender(array $args): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            null,
            null,
            'CLEARACCESS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
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
