<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\RestoreCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickDropService;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        self::assertSame(72, $command->getOrder());
        self::assertSame('restore.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::RESTORE, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
        self::assertNull($command->getAuditData(new stdClass()));
    }

    #[Test]
    public function executeRestoresPendingDeletionNick(): void
    {
        $nick = RegisteredNick::createPending('Target', 'hash', 'user@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();
        $nick->markPendingDeletion();

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($nick);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('restoreNick')->with($nick, 'OperUser');

        $messages = [];
        $context = $this->createContext(['Target'], $messages);
        $command = new RestoreCommand($repo, $dropService);

        $command->execute($context);

        self::assertContains('restore.success', $messages);
        $auditData = $command->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('Target', $auditData->target);
    }

    #[Test]
    public function executeRepliesWhenNickIsNotPendingDeletion(): void
    {
        $nick = RegisteredNick::createPending('Target', 'hash', 'user@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($nick);
        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::never())->method('restoreNick');

        $messages = [];
        (new RestoreCommand($repo, $dropService))->execute($this->createContext(['Target'], $messages));

        self::assertContains('restore.not_pending_deletion', $messages);
    }

    #[Test]
    public function executeReturnsEarlyWithoutSender(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::never())->method('findByNick');
        $messages = [];

        (new RestoreCommand($repo, $this->createStub(NickDropService::class)))->execute($this->createContextWithoutSender(['Target'], $messages));

        self::assertSame([], $messages);
    }

    #[Test]
    public function executeRepliesWhenNickDoesNotExist(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn(null);
        $messages = [];

        (new RestoreCommand($repo, $this->createStub(NickDropService::class)))->execute($this->createContext(['Target'], $messages));

        self::assertContains('restore.not_registered', $messages);
    }

    private function createCommand(): RestoreCommand
    {
        return new RestoreCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickDropService::class),
        );
    }

    /** @param string[] $args */
    private function createContext(array $args, array &$messages): NickServContext
    {
        return $this->createContextWithSender(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', ''), $args, $messages);
    }

    /** @param string[] $args */
    private function createContextWithoutSender(array $args, array &$messages): NickServContext
    {
        return $this->createContextWithSender(null, $args, $messages);
    }

    /** @param string[] $args */
    private function createContextWithSender(?SenderView $sender, array $args, array &$messages): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'RESTORE',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([]),
        );
    }
}
