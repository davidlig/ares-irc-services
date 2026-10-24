<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\ListCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\List\ListedNickAccount;
use App\NickServ\Application\UseCase\List\ListNickAccounts;
use App\NickServ\Application\UseCase\List\ListNickAccountsHandlerInterface;
use App\NickServ\Application\UseCase\List\ListNickAccountsResult;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_scalar;

#[CoversClass(ListCommand::class)]
final class ListCommandTest extends TestCase
{
    #[Test]
    public function exposesIrcopListMetadata(): void
    {
        $command = new ListCommand($this->createStub(ListNickAccountsHandlerInterface::class));

        self::assertSame('LIST', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('list.syntax', $command->getSyntaxKey());
        self::assertSame('list.help', $command->getHelpKey());
        self::assertSame(85, $command->getOrder());
        self::assertSame('list.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::LIST, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function mapsPatternAndPageThenPresentsRowsAndSafeAuditData(): void
    {
        $registeredAt = new DateTimeImmutable('2026-01-01 12:00:00 UTC');
        $lastSeenAt = new DateTimeImmutable('2026-02-03 04:05:00 UTC');
        $entry = new ListedNickAccount('Davidlig', $registeredAt, $lastSeenAt, '203.0.113.7', NickStatus::Registered);
        $handler = $this->createMock(ListNickAccountsHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new ListNickAccounts('*avi*', 2)))
            ->willReturn(new ListNickAccountsResult('*avi*', 2, 10, 11, [$entry]));

        $command = new ListCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext(['*avi*', '2'], $messages, 'Europe/Madrid'));

        self::assertTrue($outcome->success);
        self::assertSame([
            'list.header',
            'list.row [%ip%: 203.0.113.7, %last_seen%: 03/02/2026 05:05 CET, %nickname%: Davidlig, %registered%: 01/01/2026 13:00 CET, %status%: list.status_registered]',
            'list.page [%page%: 2, %pages%: 2, %total%: 11]',
        ], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('*avi*', $outcome->auditData->target);
        self::assertNull($outcome->auditData->targetIp);
        self::assertSame(['page' => 2, 'total' => 11], $outcome->auditData->extra);
    }

    #[Test]
    public function presentsNoResultsAndMissingDataAsDashes(): void
    {
        $entry = new ListedNickAccount('ForbiddenNick', null, null, null, NickStatus::Forbidden);
        $handler = $this->createStub(ListNickAccountsHandlerInterface::class);
        $handler->method('handle')->willReturn(new ListNickAccountsResult('forbidden*', 1, 50, 1, [$entry]));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['forbidden*'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame([
            'list.header',
            'list.row [%ip%: —, %last_seen%: —, %nickname%: ForbiddenNick, %registered%: —, %status%: list.status_forbidden]',
            'list.page [%page%: 1, %pages%: 1, %total%: 1]',
        ], $messages);
    }

    #[Test]
    public function presentsEmptySearchResults(): void
    {
        $handler = $this->createStub(ListNickAccountsHandlerInterface::class);
        $handler->method('handle')->willReturn(new ListNickAccountsResult('missing*', 1, 50, 0, []));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['missing*'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['list.header', 'list.empty [%pattern%: missing*]', 'list.page [%page%: 1, %pages%: 0, %total%: 0]'], $messages);
    }

    #[Test]
    public function doesNotClaimAnOutOfRangePageHasNoMatchingNicknames(): void
    {
        $handler = $this->createStub(ListNickAccountsHandlerInterface::class);
        $handler->method('handle')->willReturn(new ListNickAccountsResult('nick*', 3, 5, 10, []));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['nick*', '3'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['list.header', 'list.page [%page%: 3, %pages%: 2, %total%: 10]'], $messages);
    }

    #[Test]
    public function translatesEveryNickStatus(): void
    {
        $statuses = [
            [NickStatus::Pending, 'list.status_pending'],
            [NickStatus::Registered, 'list.status_registered'],
            [NickStatus::Suspended, 'list.status_suspended'],
            [NickStatus::PendingDeletion, 'list.status_pending_deletion'],
            [NickStatus::Forbidden, 'list.status_forbidden'],
        ];

        foreach ($statuses as [$status, $key]) {
            $handler = $this->createStub(ListNickAccountsHandlerInterface::class);
            $handler->method('handle')->willReturn(new ListNickAccountsResult(
                '*',
                1,
                50,
                1,
                [new ListedNickAccount('example', null, null, null, $status)],
            ));
            $messages = [];

            new ListCommand($handler)->execute($this->createContext(['*'], $messages));

            self::assertStringContainsString('%status%: ' . $key, $messages[1]);
        }
    }

    #[Test]
    public function rejectsInvalidPageInsteadOfSilentlyChangingThePattern(): void
    {
        $handler = $this->createMock(ListNickAccountsHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['*', 'second'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: list.syntax]'], $messages);
    }

    #[Test]
    public function normalizesNonPositivePageAndRejectsAdditionalArguments(): void
    {
        $handler = $this->createMock(ListNickAccountsHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new ListNickAccounts('*', 1)))
            ->willReturn(new ListNickAccountsResult('*', 1, 50, 0, []));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['*', '0'], $messages));

        self::assertTrue($outcome->success);

        $handler = $this->createMock(ListNickAccountsHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];
        $outcome = new ListCommand($handler)->execute($this->createContext(['*', '1', 'extra'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: list.syntax]'], $messages);
    }

    #[Test]
    public function rejectsWhenSenderIsMissing(): void
    {
        $handler = $this->createMock(ListNickAccountsHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['*'], $messages, sender: false));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    /**
     * @param list<string> $args
     * @param list<string> $messages
     */
    private function createContext(array $args, array &$messages, string $timezone = 'UTC', bool $sender = true): NickServContext
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
            $sender ? new SenderView('UID1', 'RootAdmin', 'ident', 'host', 'cloak', 'ip', true, false) : null,
            null,
            'LIST',
            $args,
            $notifier,
            $translator,
            'en',
            $timezone,
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
}
