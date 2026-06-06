<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\RestoreCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChanDropService;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

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
        self::assertNull($command->getAuditData(new stdClass()));
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

        $command->execute($context);

        self::assertContains('restore.success', $messages);
        $auditData = $command->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
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
        (new RestoreCommand($repo, $dropService))->execute($this->createContext(['#test'], $messages));

        self::assertContains('restore.not_pending_deletion', $messages);
    }

    #[Test]
    public function executeReturnsEarlyWithoutSender(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('findByChannelName');
        $messages = [];

        (new RestoreCommand($repo, $this->createStub(ChanDropService::class)))->execute($this->createContextWithoutSender(['#test'], $messages));

        self::assertSame([], $messages);
    }

    #[Test]
    public function executeRepliesInvalidChannel(): void
    {
        $messages = [];

        (new RestoreCommand($this->createStub(RegisteredChannelRepositoryInterface::class), $this->createStub(ChanDropService::class)))->execute($this->createContext(['notchannel'], $messages));

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeRepliesWhenChannelDoesNotExist(): void
    {
        $repo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repo->method('findByChannelName')->willReturn(null);
        $messages = [];

        (new RestoreCommand($repo, $this->createStub(ChanDropService::class)))->execute($this->createContext(['#test'], $messages));

        self::assertContains('restore.not_registered', $messages);
    }

    private function createCommand(): RestoreCommand
    {
        return new RestoreCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
        );
    }

    /** @param string[] $args */
    private function createContext(array $args, array &$messages): ChanServContext
    {
        return $this->createContextWithSender(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', ''), $args, $messages);
    }

    /** @param string[] $args */
    private function createContextWithoutSender(array $args, array &$messages): ChanServContext
    {
        return $this->createContextWithSender(null, $args, $messages);
    }

    /** @param string[] $args */
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
