<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface as AuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\DropCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleResult;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycle;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DropCommand::class)]
#[UsesClass(ManageChannelLifecycleHandler::class)]
#[UsesClass(ManageChannelLifecycle::class)]
#[UsesClass(ChannelLifecycleResult::class)]
final class DropCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsDrop(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('DROP', $cmd->getName());
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

        self::assertSame('drop.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('drop.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyFive(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(75, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('drop.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsDropPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::DROP, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $cmd = $this->createCommandWith($channelRepository, $this->createStub(ChanDropService::class));

        $messages = [];
        $context = $this->createContext(null, null, ['#test'], $messages, channelRepository: $channelRepository);

        $outcome = $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithInvalidChannelNameRepliesInvalidChannel(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $cmd = $this->createCommandWith($channelRepository, $this->createStub(ChanDropService::class));

        $messages = [];
        $context = $this->createContext($sender, null, ['notachannel'], $messages);

        $cmd->execute($context);

        self::assertContains('drop.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithNonexistentChannelRepliesNotRegistered(): void
    {
        $sender = $this->createSender();
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $cmd = $this->createCommandWith($channelRepository, $this->createStub(ChanDropService::class));

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $cmd->execute($context);

        self::assertContains('drop.not_registered', $messages);
    }

    #[Test]
    public function executeDropsChannelSuccessfully(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 42);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('softDropChannel')->with($channel, self::isInstanceOf(DateTimeImmutable::class), 'OperUser');

        $cmd = $this->createCommandWith($channelRepository, $dropService);

        $messages = [];
        $context = $this->createContext($sender, null, ['#test'], $messages, channelRepository: $channelRepository);

        $outcome = $cmd->execute($context);

        self::assertContains('drop.success', $messages);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
    }

    #[Test]
    public function executeWithPendingDeletionChannelRepliesPendingDeletion(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 42);
        $channel->markPendingDeletion(new DateTimeImmutable());
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn($channel);
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('softDropChannel');

        $messages = [];
        $this->createCommandWith($repo, $dropService)->execute($this->createContext($sender, null, ['#test'], $messages, channelRepository: $repo));

        self::assertContains('drop.pending_deletion', $messages);
    }

    #[Test]
    public function executeWithForceRequiresForcePermission(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 42);
        $channel->markPendingDeletion(new DateTimeImmutable());
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn($channel);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(false);
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('hardDropChannel');

        $messages = [];
        $this->createCommandWith($repo, $dropService, $authorization)->execute($this->createContext($sender, null, ['#test', 'force'], $messages, channelRepository: $repo));

        self::assertContains('error.permission_denied', $messages);
    }

    #[Test]
    public function executeWithForceHardDropsWhenPermissionGranted(): void
    {
        $sender = $this->createSender();
        $channel = $this->createChannelWithId('#test', 42);
        $channel->markPendingDeletion(new DateTimeImmutable());
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn($channel);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with($channel, self::isInstanceOf(DateTimeImmutable::class), 'manual-force', 'OperUser');

        $messages = [];
        $cmd = $this->createCommandWith($repo, $dropService, $authorization);
        $context = $this->createContext($sender, null, ['#test', 'force'], $messages, channelRepository: $repo);
        $outcome = $cmd->execute($context);

        self::assertContains('drop.force_success', $messages);
        self::assertTrue($outcome->auditData?->extra['force']);
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

    private function createCommand(): DropCommand
    {
        return $this->createCommandWith(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
        );
    }

    private function createCommandWith(
        RegisteredChannelRepositoryInterface $channels,
        ChanDropService $dropService,
        ?AuthorizationCheckerInterface $authorizationChecker = null,
    ): DropCommand {
        return new DropCommand(
            new ManageChannelLifecycleHandler(
                $channels,
                $dropService,
                $this->createStub(ChannelForbiddenService::class),
                $this->createStub(ChannelSuspensionService::class),
                $this->createStub(EventBusInterface::class),
            ),
            $authorizationChecker,
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o');
    }

    private function createChannelWithId(string $name, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), $name, 1, 'Test description');

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, $id);

        return $channel;
    }

    /**
     * @param array<string> $args
     * @param array<string> $messages
     */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            $sender,
            $senderAccount,
            'DROP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
