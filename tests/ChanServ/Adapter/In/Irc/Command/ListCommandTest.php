<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\ListCommand;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\List\ListedRegisteredChannel;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannels;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsHandlerInterface;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsResult;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
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
        $command = new ListCommand($this->createStub(ListRegisteredChannelsHandlerInterface::class));

        self::assertSame('LIST', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('list.syntax', $command->getSyntaxKey());
        self::assertSame('list.help', $command->getHelpKey());
        self::assertSame(210, $command->getOrder());
        self::assertSame('list.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(ChanServPermission::LIST, $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function mapsPatternAndPageThenPresentsFounderAndSafeAuditData(): void
    {
        $registeredAt = new DateTimeImmutable('2026-01-01 12:00:00 UTC');
        $lastUsedAt = new DateTimeImmutable('2026-02-03 04:05:00 UTC');
        $entry = new ListedRegisteredChannel('#davidlig', 'Davinia', $registeredAt, $lastUsedAt, ChannelStatus::Active);
        $handler = $this->createMock(ListRegisteredChannelsHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new ListRegisteredChannels('*avi*', 2)))
            ->willReturn(new ListRegisteredChannelsResult('*avi*', 2, 10, 11, [$entry]));

        $messages = [];
        $outcome = new ListCommand($handler)->execute($this->createContext(['*avi*', '2'], $messages, 'Europe/Madrid'));

        self::assertTrue($outcome->success);
        self::assertSame([
            'list.header',
            'list.row [%channel%: #davidlig, %founder%: Davinia, %last_used%: 03/02/2026 05:05 CET, %registered%: 01/01/2026 13:00 CET, %status%: list.status_active]',
            'list.page [%page%: 2, %pages%: 2, %total%: 11]',
        ], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('*avi*', $outcome->auditData->target);
        self::assertNull($outcome->auditData->targetIp);
        self::assertSame(['page' => 2, 'total' => 11], $outcome->auditData->extra);
    }

    #[Test]
    public function presentsEmptyResultsAndMissingFieldsAsDashes(): void
    {
        $handler = $this->createStub(ListRegisteredChannelsHandlerInterface::class);
        $handler->method('handle')->willReturn(new ListRegisteredChannelsResult('*', 1, 50, 1, [
            new ListedRegisteredChannel('#forbidden', null, null, null, ChannelStatus::Forbidden),
        ]));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['*'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame([
            'list.header',
            'list.row [%channel%: #forbidden, %founder%: —, %last_used%: —, %registered%: —, %status%: list.status_forbidden]',
            'list.page [%page%: 1, %pages%: 1, %total%: 1]',
        ], $messages);
    }

    #[Test]
    public function presentsNoResultsAndOutOfRangePages(): void
    {
        $handler = $this->createStub(ListRegisteredChannelsHandlerInterface::class);
        $handler->method('handle')->willReturn(new ListRegisteredChannelsResult('missing*', 3, 5, 10, []));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['missing*', '3'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['list.header', 'list.page [%page%: 3, %pages%: 2, %total%: 10]'], $messages);
    }

    #[Test]
    public function presentsEmptyPatternSearchResults(): void
    {
        $handler = $this->createStub(ListRegisteredChannelsHandlerInterface::class);
        $handler->method('handle')->willReturn(new ListRegisteredChannelsResult('missing*', 1, 50, 0, []));
        $messages = [];

        new ListCommand($handler)->execute($this->createContext(['missing*'], $messages));

        self::assertSame(['list.header', 'list.empty [%pattern%: missing*]', 'list.page [%page%: 1, %pages%: 0, %total%: 0]'], $messages);
    }

    #[Test]
    public function translatesEveryChannelStatus(): void
    {
        $statuses = [
            [ChannelStatus::Active, 'list.status_active'],
            [ChannelStatus::Suspended, 'list.status_suspended'],
            [ChannelStatus::PendingDeletion, 'list.status_pending_deletion'],
            [ChannelStatus::Forbidden, 'list.status_forbidden'],
        ];

        foreach ($statuses as [$status, $key]) {
            $handler = $this->createStub(ListRegisteredChannelsHandlerInterface::class);
            $handler->method('handle')->willReturn(new ListRegisteredChannelsResult(
                '*',
                1,
                50,
                1,
                [new ListedRegisteredChannel('#example', null, null, null, $status)],
            ));
            $messages = [];

            new ListCommand($handler)->execute($this->createContext(['*'], $messages));

            self::assertStringContainsString('%status%: ' . $key, $messages[1]);
        }
    }

    #[Test]
    public function rejectsInvalidPagesAndAdditionalArguments(): void
    {
        $handler = $this->createMock(ListRegisteredChannelsHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['*', 'second'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: list.syntax]'], $messages);

        $messages = [];
        $outcome = new ListCommand($handler)->execute($this->createContext(['*', '2', 'extra'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: list.syntax]'], $messages);
    }

    #[Test]
    public function normalizesNonPositivePageAndRejectsMissingPatternOrSender(): void
    {
        $handler = $this->createMock(ListRegisteredChannelsHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new ListRegisteredChannels('*', 1)))
            ->willReturn(new ListRegisteredChannelsResult('*', 1, 50, 0, []));
        $messages = [];

        $outcome = new ListCommand($handler)->execute($this->createContext(['*', '0'], $messages));

        self::assertTrue($outcome->success);

        $handler = $this->createMock(ListRegisteredChannelsHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];
        $outcome = new ListCommand($handler)->execute($this->createContext([], $messages));
        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: list.syntax]'], $messages);

        $messages = [];
        $outcome = new ListCommand($handler)->execute($this->createContext(['*'], $messages, sender: false));
        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    /**
     * @param list<string> $args
     * @param list<string> $messages
     */
    private function createContext(array $args, array &$messages, string $timezone = 'UTC', bool $sender = true): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            unset($params['%bot%'], $params['%chanserv%']);
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

        return new ChanServContext(
            $sender ? new SenderView('UID1', 'RootAdmin', 'ident', 'host', 'cloak', 'ip', true, false) : null,
            null,
            'LIST',
            $args,
            $notifier,
            $translator,
            'en',
            $timezone,
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
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'chanserv';
            }

            public function getNickname(): string
            {
                return 'ChanServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }
}
