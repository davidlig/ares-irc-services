<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\HistoryCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\History\HistoryNick;
use App\NickServ\Application\UseCase\History\HistoryNickAction;
use App\NickServ\Application\UseCase\History\HistoryNickHandlerInterface;
use App\NickServ\Application\UseCase\History\HistoryNickResult;
use App\NickServ\Application\UseCase\History\NickHistoryEntryView;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function inet_pton;
use function is_scalar;

use const DATE_ATOM;

#[CoversClass(HistoryCommand::class)]
final class HistoryCommandTest extends TestCase
{
    #[Test]
    public function exposesHistoryMetadata(): void
    {
        $command = new HistoryCommand($this->createStub(HistoryNickHandlerInterface::class), $this->clock());

        self::assertSame('HISTORY', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('history.syntax', $command->getSyntaxKey());
        self::assertSame('history.help', $command->getHelpKey());
        self::assertSame(200, $command->getOrder());
        self::assertSame('history.short', $command->getShortDescKey());
        self::assertCount(4, $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::HISTORY, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext(null, $messages, ['Target', 'VIEW']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function repliesSyntaxErrorWhenActionIsInvalid(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'UNKNOWN']));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: history.syntax]'], $messages);
    }

    #[Test]
    public function rejectsAddWhenMessageMissingOrEmpty(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new HistoryCommand($handler, $this->clock());

        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'ADD']));
        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: history.add.syntax]'], $messages);

        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'ADD', '   ']));
        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: history.add.syntax]'], $messages);
    }

    #[Test]
    public function handlesAddSuccessfully(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'box.net', 'cloak', base64_encode((string) inet_pton('127.0.0.1')));
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (HistoryNick $dto): bool => 'Target' === $dto->nickname
                && HistoryNickAction::Add === $dto->action
                && 'A note message' === $dto->message
                && 'Oper' === $dto->operatorNick
                && '2026-09-06T12:00:00+00:00' === $dto->occurredAt->format(DATE_ATOM)
                && '127.0.0.1' === $dto->operatorIp
                && 'ident@box.net' === $dto->operatorHost,
        ))->willReturn(HistoryNickResult::addSuccess('Target', 'A note message'));

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'add', 'A', 'note', 'message']));

        self::assertTrue($outcome->success);
        self::assertSame(['history.add.success [%nickname%: Target]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('Target', $outcome->auditData->target);
        self::assertSame('A note message', $outcome->auditData->reason);
    }

    #[Test]
    public function handlesAddWithFallbackIps(): void
    {
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->willReturn(HistoryNickResult::addSuccess('Target', 'Note'));

        $command = new HistoryCommand($handler, $this->clock());

        $sender1 = new SenderView('UID1', 'Oper', 'ident', 'box.net', 'cloak', '');
        $messages1 = [];
        $command->execute($this->createContext($sender1, $messages1, ['Target', 'add', 'Note']));

        $sender2 = new SenderView('UID1', 'Oper', 'ident', 'box.net', 'cloak', '???notbase64???');
        $messages2 = [];
        $command->execute($this->createContext($sender2, $messages2, ['Target', 'add', 'Note']));

        self::assertCount(1, $messages1);
        self::assertCount(1, $messages2);
    }

    #[Test]
    public function rejectsDelWhenIdMissingOrInvalid(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new HistoryCommand($handler, $this->clock());

        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'DEL']));
        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: history.del.syntax]'], $messages);

        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'DEL', '0']));
        self::assertFalse($outcome->success);
        self::assertSame(['history.del.invalid_id [%id%: 0]'], $messages);
    }

    #[Test]
    public function handlesDelSuccessfully(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (HistoryNick $dto): bool => 'Target' === $dto->nickname
                && HistoryNickAction::Del === $dto->action
                && 42 === $dto->entryId,
        ))->willReturn(HistoryNickResult::delSuccess('Target', 42));

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'del', '42']));

        self::assertTrue($outcome->success);
        self::assertSame(['history.del.success [%id%: 42]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('Target', $outcome->auditData->target);
        self::assertSame(42, $outcome->auditData->extra['entry_id'] ?? null);
    }

    #[Test]
    public function handlesClearSuccessfully(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (HistoryNick $dto): bool => 'Target' === $dto->nickname
                && HistoryNickAction::Clear === $dto->action,
        ))->willReturn(HistoryNickResult::clearSuccess('Target', 5));

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'clear']));

        self::assertTrue($outcome->success);
        self::assertSame(['history.clear.success [%count%: 5, %nickname%: Target]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame(5, $outcome->auditData->extra['count'] ?? null);
    }

    #[Test]
    public function handlesViewWithPaginationAndFormatting(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (HistoryNick $dto): bool => 'Target' === $dto->nickname
                && HistoryNickAction::View === $dto->action
                && 2 === $dto->page
                && !$dto->showAll,
        ))->willReturn(HistoryNickResult::viewSuccess(
            targetNick: 'Target',
            entries: [
                new NickHistoryEntryView(
                    id: 10,
                    performedAt: new DateTimeImmutable('2026-09-06 12:00:00 UTC'),
                    action: 'EMAIL_CHANGE',
                    performedBy: 'Admin',
                    performedByNickId: 1,
                    operatorExists: false,
                    message: 'history.message.email_changed',
                    extraData: [
                        'duration' => '30d',
                        'expires_at' => '2026-10-06',
                        'old_email' => 'old@mail.com',
                        'new_email' => 'new@mail.com',
                        'method' => 'web',
                        'ip' => '1.2.3.4',
                        'host' => 'admin.net',
                    ],
                ),
                new NickHistoryEntryView(
                    id: 2,
                    performedAt: new DateTimeImmutable('2026-09-06 12:00:00 UTC'),
                    action: 'MANUAL_NOTE',
                    performedBy: 'Oper2',
                    performedByNickId: null,
                    operatorExists: false,
                    message: 'Plain manual note without prefix',
                    extraData: [
                        'ip' => ['non-scalar'],
                    ],
                ),
            ],
            start: 11,
            end: 20,
            total: 35,
            page: 2,
            totalPages: 4,
            showAll: false,
        ));

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'view', '2']));

        self::assertFalse($outcome->success);
        self::assertContains('history.view.header [%end%: 20, %nickname%: Target, %start%: 11, %total%: 35]', $messages);
        self::assertContains('history.view.page_hint [%next_page%: 3, %nickname%: Target]', $messages);
    }

    #[Test]
    public function handlesViewShowAllOption(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (HistoryNick $dto): bool => 'Target' === $dto->nickname
                && HistoryNickAction::View === $dto->action
                && $dto->showAll,
        ))->willReturn(HistoryNickResult::viewSuccess(
            targetNick: 'Target',
            entries: [],
            start: 1,
            end: 5,
            total: 5,
            page: 1,
            totalPages: 1,
            showAll: true,
        ));

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'view', 'all']));

        self::assertFalse($outcome->success);
        self::assertContains('history.view.header [%end%: 5, %nickname%: Target, %start%: 1, %total%: 5]', $messages);
    }

    #[Test]
    public function handlesViewPageBelowOneDefaultsToOne(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (HistoryNick $dto): bool => 1 === $dto->page,
        ))->willReturn(HistoryNickResult::viewNoEntries('Target'));

        $command = new HistoryCommand($handler, $this->clock());
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'view', '-5']));

        self::assertFalse($outcome->success);
        self::assertSame(['history.view.no_entries [%nickname%: Target]'], $messages);
    }

    #[Test]
    public function presentsRejectionOutcomes(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(HistoryNickHandlerInterface::class);
        $handler->expects(self::exactly(3))->method('handle')->willReturnOnConsecutiveCalls(
            HistoryNickResult::notRegistered('Target'),
            HistoryNickResult::delNotFound(99),
            HistoryNickResult::delInvalidId('abc'),
        );

        $command = new HistoryCommand($handler, $this->clock());

        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Target', 'view']));
        self::assertSame(['history.not_registered [%nickname%: Target]'], $messages);

        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Target', 'clear']));
        self::assertSame(['history.del.not_found [%id%: 99]'], $messages);

        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Target', 'clear']));
        self::assertSame(['history.del.invalid_id [%id%: abc]'], $messages);
    }

    /**
     * @param string[] $messages
     * @param string[] $args
     */
    private function createContext(?SenderView $sender, array &$messages, array $args): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            unset($params['%bot%'], $params['%nickserv%']);
            if ([] === $params) {
                return $id;
            }
            ksort($params);
            $formatted = [];
            foreach ($params as $key => $value) {
                $formatted[] = (string) $key . ': ' . (is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
            }

            return $id . ' [' . implode(', ', $formatted) . ']';
        });

        return new NickServContext(
            $sender,
            null,
            'HISTORY',
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
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-06 12:00:00 UTC'));

        return $clock;
    }
}
