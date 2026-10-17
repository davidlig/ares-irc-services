<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\PendientesCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ChannelRegistrationRow;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrations;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrationsHandlerInterface;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrationsResult;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(PendientesCommand::class)]
final class PendientesCommandTest extends TestCase
{
    private static function row(
        string $channel = '#SitioChat',
        string $requesterNick = 'JuanJo_Jaen',
        string $description = 'Canal de pruebas',
        DateTimeImmutable $createdAt = new DateTimeImmutable('2026-10-15 10:00:00'),
    ): ChannelRegistrationRow {
        return new ChannelRegistrationRow($channel, $requesterNick, $description, $createdAt, null, null);
    }

    /**
     * @param list<array<string, mixed>> $params
     */
    private function createTranslator(array &$params): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $given = []) use (&$params): string {
                $params[] = ['id' => $id, 'params' => $given];

                return $id;
            },
        );

        return $translator;
    }

    /**
     * @param list<string> $messages
     */
    private function createNotifier(array &$messages): CRegServNotifierInterface
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CReG');
        $notifier->method('sendMessage')->willReturnCallback(
            static function (string $target, string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        return $notifier;
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'cregserv';
            }

            public function getNickname(): string
            {
                return 'CReG';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    /**
     * @param list<string>               $messages
     * @param list<array<string, mixed>> $params
     */
    private function createContext(array &$messages, array &$params): CRegServContext
    {
        return new CRegServContext(
            new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip'),
            new CRegServAccountView(5, 'NetAdmin', 'en'),
            'PENDIENTES',
            [],
            $this->createNotifier($messages),
            $this->createTranslator($params),
            'en',
            'UTC',
            'NOTICE',
            new CRegServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }

    /**
     * @param ChannelRegistrationRow[] $rows
     */
    private function createCommand(array $rows, ?ChannelRegistrationStatus &$askedFor = null): PendientesCommand
    {
        $handler = $this->createMock(ListChannelRegistrationsHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(
                static function (ListChannelRegistrations $command) use (&$askedFor, $rows): ListChannelRegistrationsResult {
                    $askedFor = $command->status;

                    return new ListChannelRegistrationsResult($rows);
                },
            );

        return new PendientesCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertSame('PENDIENTES', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs(), 'It takes no arguments, as in pulsar do_pendientes.');
        self::assertSame(5, $command->getOrder());
        self::assertTrue($command->isOperOnly(), 'pulsar guards it with is_services_oper.');
        self::assertSame(CRegServPermission::PENDIENTES, $command->getRequiredPermission());
        self::assertSame('pendientes.syntax', $command->getSyntaxKey());
        self::assertSame('pendientes.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $command = new PendientesCommand($this->createStub(ListChannelRegistrationsHandlerInterface::class));

        self::assertSame('pendientes.help', $command->getHelpKey());
    }

    #[Test]
    public function getExampleKeyReturnsCorrectKey(): void
    {
        $command = new PendientesCommand($this->createStub(ListChannelRegistrationsHandlerInterface::class));

        self::assertSame('pendientes.example', $command->getExampleKey());
    }

    #[Test]
    public function asksForThePendingWorkQueue(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertSame(ChannelRegistrationStatus::Pending, $askedFor);
    }

    #[Test]
    public function saysSoOutLoudWhenTheQueueIsEmpty(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertSame(['pendientes.empty'], $messages, 'An empty queue must be stated, never shown as a bare list.');
    }

    #[Test]
    public function printsOneLinePerRequestAndClosesTheList(): void
    {
        $messages = [];
        $params = [];
        $command = $this->createCommand([self::row('#Viejo'), self::row('#Nuevo')]);

        $command->execute($this->createContext($messages, $params));

        self::assertSame(['pendientes.line', 'pendientes.line', 'pendientes.end'], $messages);
    }

    #[Test]
    public function carriesTheChannelAskerDateAndDescriptionOnEachLine(): void
    {
        $messages = [];
        $params = [];
        $command = $this->createCommand([self::row(
            channel: '#SitioChat',
            requesterNick: 'JuanJo_Jaen',
            description: 'Canal de la comunidad',
            createdAt: new DateTimeImmutable('2026-10-15 10:00:00'),
        )]);

        $command->execute($this->createContext($messages, $params));

        /** @var array<string, mixed> $line */
        $line = $params[0]['params'];

        self::assertSame('#SitioChat', $line['%channel%']);
        self::assertSame('JuanJo_Jaen', $line['%requester%']);
        self::assertSame('Canal de la comunidad', $line['%description%']);
        self::assertSame('15/10/2026 10:00 UTC', $line['%date%']);
    }

    #[Test]
    public function neverEndsAListItDidNotStart(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertNotContains('pendientes.end', $messages);
    }
}
