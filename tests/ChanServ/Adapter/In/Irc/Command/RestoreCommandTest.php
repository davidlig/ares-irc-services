<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\RestoreCommand;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestoreCommand::class)]
final class RestoreCommandTest extends TestCase
{
    #[Test]
    public function metadataReturnsExpectedValues(): void
    {
        $command = $this->createCommand();

        self::assertSame('RESTORE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('restore.syntax', $command->getSyntaxKey());
        self::assertSame('restore.help', $command->getHelpKey());
        self::assertSame(76, $command->getOrder());
        self::assertSame('restore.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(ChanServPermission::RESTORE, $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function executeRestoresPendingDeletionChannel(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->markPendingDeletion();

        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn($channel);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('restoreChannel')->with($channel, 'OperUser');

        $messages = [];
        $context = $this->createContext(['#test'], $messages);
        $command = new RestoreCommand($repo, $dropService);

        $outcome = $command->execute($context);

        self::assertContains('restore.success', $messages);
        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);

        self::assertFalse($command->execute($this->createContextWithoutSender(['#test'], $messages))->success);
    }

    #[Test]
    public function executeRepliesWhenChannelIsNotPendingDeletion(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn($channel);
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('restoreChannel');

        $messages = [];
        new RestoreCommand($repo, $dropService)->execute($this->createContext(['#test'], $messages));

        self::assertContains('restore.not_pending_deletion', $messages);
    }

    #[Test]
    public function executeReturnsEarlyWithoutSender(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('findByChannelName');
        $messages = [];

        new RestoreCommand($repo, $this->createStub(ChanDropService::class))->execute($this->createContextWithoutSender(['#test'], $messages));

        self::assertSame([], $messages);
    }

    #[Test]
    public function executeReturnsRejectedWhenValidatedChannelHasNoSender(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->markPendingDeletion();
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel);
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('restoreChannel');
        $messages = [];

        $outcome = new RestoreCommand($repository, $dropService)->execute($this->createContextWithoutSender(['#test'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function executeRepliesInvalidChannel(): void
    {
        $messages = [];

        new RestoreCommand($this->createStub(RegisteredChannelRepositoryInterface::class), $this->createStub(ChanDropService::class))->execute($this->createContext(['notchannel'], $messages));

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeRepliesWhenChannelDoesNotExist(): void
    {
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn(null);
        $messages = [];

        new RestoreCommand($repo, $this->createStub(ChanDropService::class))->execute($this->createContext(['#test'], $messages));

        self::assertContains('restore.not_registered', $messages);
    }

    private function createCommand(): RestoreCommand
    {
        return new RestoreCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
        );
    }

    /**
     * @param array<string> $args
     * @param array<string> $messages
     */
    private function createContext(array $args, array &$messages): ChanServContext
    {
        return $this->createContextWithSender(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o'), $args, $messages);
    }

    /**
     * @param array<string> $args
     * @param array<string> $messages
     */
    private function createContextWithoutSender(array $args, array &$messages): ChanServContext
    {
        return $this->createContextWithSender(null, $args, $messages);
    }

    /**
     * @param array<string> $args
     * @param array<string> $messages
     */
    private function createContextWithSender(?SenderView $sender, array $args, array &$messages): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            $sender,
            null,
            'RESTORE',
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
            new ServiceNicknameRegistry([]),
        );
    }
}
