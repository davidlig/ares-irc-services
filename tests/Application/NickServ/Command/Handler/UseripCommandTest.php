<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\UseripCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UseripCommand::class)]
final class UseripCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsUserip(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('USERIP', $cmd->getName());
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

        self::assertSame('userip.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('userip.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSixty(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(60, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('userip.short', $cmd->getShortDescKey());
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
    public function getRequiredPermissionReturnsUseripPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::USERIP, $cmd->getRequiredPermission());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $registry = new NickServCommandRegistry([]);

        $cmd = new UseripCommand($userLookup);
        $context = $this->createContext(null, ['SomeUser'], $notifier, $translator, $registry);

        $cmd->execute($context);
    }

    #[Test]
    public function executeUserNotOnlineRepliesNotOnline(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $registry = new NickServCommandRegistry([]);

        $cmd = new UseripCommand($userLookup);
        $context = $this->createContext($sender, ['UnknownUser'], $notifier, $translator, $registry);

        $cmd->execute($context);

        self::assertContains('userip.not_online', $messages);
    }

    #[Test]
    public function executeUserOnlineRepliesWithIpAndHost(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'TargetUser', 'targeti', 'targeth', 'targetc', 'targetip', false, false, 'SID1', 'targeth', 'i', '');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);
        $registry = new NickServCommandRegistry([]);

        $cmd = new UseripCommand($userLookup);
        $context = $this->createContext($sender, ['TargetUser'], $notifier, $translator, $registry);

        $cmd->execute($context);

        self::assertContains('userip.result', $messages);
    }

    #[Test]
    public function executeDecodesIpV4Correctly(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        // IP 192.168.1.1 in base64: base64(pack('C4', 192, 168, 1, 1)) = wKgBAQ==
        $ipBase64 = base64_encode(pack('C4', 192, 168, 1, 1));
        $target = new SenderView('UID2', 'TargetUser', 'targeti', 'targeth', 'targetc', $ipBase64, false, false, 'SID1', 'displayhost.example.com', 'i', '');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);
        $registry = new NickServCommandRegistry([]);

        $cmd = new UseripCommand($userLookup);
        $context = $this->createContext($sender, ['TargetUser'], $notifier, $translator, $registry);

        $cmd->execute($context);

        self::assertContains('userip.result', $messages);
        self::assertCount(1, $messages, 'Should send exactly one message');
    }

    #[Test]
    public function executeUsesDisplayHostWhenAvailable(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'TargetUser', 'targeti', 'targeth', 'targetc', 'targetip', false, false, 'SID1', 'my.vhost.example.com', 'i', '');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);
        $registry = new NickServCommandRegistry([]);

        $cmd = new UseripCommand($userLookup);
        $context = $this->createContext($sender, ['TargetUser'], $notifier, $translator, $registry);

        $cmd->execute($context);

        self::assertContains('userip.result', $messages);
        self::assertCount(1, $messages, 'Should send exactly one message');
    }

    private function createCommand(): UseripCommand
    {
        return new UseripCommand(
            $this->createStub(NetworkUserLookupPort::class),
        );
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        NickServCommandRegistry $registry,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'USERIP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider1->method('getServiceKey')->willReturn('nickserv');
        $provider1->method('getNickname')->willReturn('NickServ');

        $provider2 = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider2->method('getServiceKey')->willReturn('chanserv');
        $provider2->method('getNickname')->willReturn('ChanServ');

        $provider3 = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider3->method('getServiceKey')->willReturn('memoserv');
        $provider3->method('getNickname')->willReturn('MemoServ');

        $provider4 = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider4->method('getServiceKey')->willReturn('operserv');
        $provider4->method('getNickname')->willReturn('OperServ');

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
