<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\ForbidvhostCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\ForbiddenPatternValidator;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\ForbiddenVhostService;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\ForbiddenVhost;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ForbidvhostCommand::class)]
final class ForbidvhostCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsForbidvhost(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('FORBIDVHOST', $cmd->getName());
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

        self::assertSame('forbidvhost.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbidvhost.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbidvhost.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsExpectedArray(): void
    {
        $cmd = $this->createCommand();

        $help = $cmd->getSubCommandHelp();

        self::assertCount(3, $help);
        self::assertSame('ADD', $help[0]['name']);
        self::assertSame('DEL', $help[1]['name']);
        self::assertSame('LIST', $help[2]['name']);
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsForbidvhost(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::FORBIDVHOST, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getOrderReturnsSeventyFive(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(72, $cmd->getOrder());
    }

    #[Test]
    public function addForbiddenPattern(): void
    {
        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findByPattern')->with('pirated.com')->willReturn(null);
        $repo->expects(self::once())->method('save');

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['ADD', 'pirated.com'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.add.done', $messages);
    }

    #[Test]
    public function addRejectsInvalidPattern(): void
    {
        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['ADD', 'invalid pattern!'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.add.invalid', $messages);
    }

    #[Test]
    public function addRejectsDuplicatePattern(): void
    {
        $existing = ForbiddenVhost::create('pirated.com', 1);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findByPattern')->with('pirated.com')->willReturn($existing);
        $repo->expects(self::never())->method('save');

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['ADD', 'pirated.com'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.add.already_exists', $messages);
    }

    #[Test]
    public function addWithMissingPatternShowsSyntax(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['ADD'], $messages);

        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function delRemovesPattern(): void
    {
        $existing = ForbiddenVhost::create('pirated.com', 1);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findByPattern')->with('pirated.com')->willReturn($existing);
        $repo->expects(self::once())->method('remove')->with($existing);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['DEL', 'pirated.com'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.del.done', $messages);
    }

    #[Test]
    public function delRejectsNonexistentPattern(): void
    {
        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findByPattern')->with('pirated.com')->willReturn(null);
        $repo->expects(self::never())->method('remove');

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['DEL', 'pirated.com'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.del.not_found', $messages);
    }

    #[Test]
    public function delWithMissingPatternShowsSyntax(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['DEL'], $messages);

        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function listShowsAllPatterns(): void
    {
        $forbidden1 = ForbiddenVhost::create('pirated.com', 10);
        $forbidden2 = ForbiddenVhost::create('badhost.com', 20);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findAll')->willReturn([$forbidden1, $forbidden2]);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['LIST'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.list.header', $messages);
    }

    #[Test]
    public function listEmpty(): void
    {
        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findAll')->willReturn([]);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['LIST'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.list.empty', $messages);
    }

    #[Test]
    public function unknownSubcommand(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['INVALID'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.unknown_sub', $messages);
    }

    #[Test]
    public function executeDoesNothingWhenSenderIsNull(): void
    {
        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::never())->method('findByPattern');
        $repo->expects(self::never())->method('save');

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContextWithNullSender(['ADD', 'test.com'], $messages);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function addWithEmptyPatternShowsSyntax(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['ADD', '   '], $messages);

        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function delWithEmptyPatternShowsSyntax(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['DEL', '   '], $messages);

        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function listShowsUnknownCreatorWhenNoCreatorId(): void
    {
        $forbidden = ForbiddenVhost::create('pirated.com', null);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findAll')->willReturn([$forbidden]);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['LIST'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.list.header', $messages);
    }

    #[Test]
    public function listShowsUnknownCreatorWhenCreatorNotSender(): void
    {
        $forbidden = ForbiddenVhost::create('pirated.com', 999);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findAll')->willReturn([$forbidden]);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['LIST'], $messages);

        $cmd->execute($context);

        self::assertContains('forbidvhost.list.header', $messages);
    }

    #[Test]
    public function listShowsCreatorNameWhenCreatorIsSender(): void
    {
        $ref = new ReflectionClass(ForbiddenVhost::class);
        $forbidden = ForbiddenVhost::create('pirated.com', 1);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($forbidden, 10);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findAll')->willReturn([$forbidden]);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContextWithSenderAccount(['LIST'], $messages, 1);

        $cmd->execute($context);

        self::assertContains('forbidvhost.list.header', $messages);
        self::assertContains('forbidvhost.list.entry', $messages);
    }

    #[Test]
    public function getAuditDataReturnsNullInitially(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['ADD'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulAdd(): void
    {
        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findByPattern')->with('pirated.com')->willReturn(null);
        $repo->expects(self::once())->method('save');

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['ADD', 'pirated.com'], $messages);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertNotNull($auditData);
        self::assertSame('pirated.com', $auditData->target);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulDel(): void
    {
        $existing = ForbiddenVhost::create('pirated.com', 1);

        $repo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $repo->expects(self::once())->method('findByPattern')->with('pirated.com')->willReturn($existing);
        $repo->expects(self::once())->method('remove')->with($existing);

        $service = new ForbiddenVhostService($repo);
        $cmd = new ForbidvhostCommand($repo, $service, new ForbiddenPatternValidator(), $this->createStub(LoggerInterface::class));

        $messages = [];
        $context = $this->createContext(['DEL', 'pirated.com'], $messages);

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertNotNull($auditData);
        self::assertSame('pirated.com', $auditData->target);
    }

    private function createCommand(): ForbidvhostCommand
    {
        $repo = $this->createStub(ForbiddenVhostRepositoryInterface::class);
        $service = new ForbiddenVhostService($repo);

        return new ForbidvhostCommand(
            $repo,
            $service,
            new ForbiddenPatternValidator(),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function createContext(array $args, array &$messages): NickServContext
    {
        $sender = new SenderView('UID123', 'TestUser', 'ident', 'host', 'name', 'ip');

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'FORBIDVHOST',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createContextWithNullSender(array $args, array &$messages): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            null,
            null,
            'FORBIDVHOST',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    private function createContextWithSenderAccount(array $args, array &$messages, int $senderAccountId): NickServContext
    {
        $sender = new SenderView('UID123', 'TestUser', 'ident', 'host', 'name', 'ip');

        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getId')->willReturn($senderAccountId);
        $account->method('getNickname')->willReturn('TestUser');

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            $account,
            'FORBIDVHOST',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }
}
