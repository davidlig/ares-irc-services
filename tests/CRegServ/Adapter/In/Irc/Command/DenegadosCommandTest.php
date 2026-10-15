<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\DenegadosCommand;
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

#[CoversClass(DenegadosCommand::class)]
final class DenegadosCommandTest extends TestCase
{
    private static function row(
        string $channel = '#Denegado',
        ?string $deniedReason = 'Spam',
        DateTimeImmutable $deniedAt = new DateTimeImmutable('2026-10-15 11:00:00'),
    ): ChannelRegistrationRow {
        return new ChannelRegistrationRow($channel, 'JuanJo_Jaen', 'Canal de pruebas', new DateTimeImmutable('2026-10-15 10:00:00'), $deniedReason, $deniedAt);
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
            'DENEGADOS',
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
    private function createCommand(array $rows, ?ChannelRegistrationStatus &$askedFor = null): DenegadosCommand
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

        return new DenegadosCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertSame('DENEGADOS', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs(), 'It takes no arguments, as in pulsar do_denegados.');
        self::assertSame(6, $command->getOrder());
        self::assertTrue($command->isOperOnly(), 'pulsar guards it with is_services_oper.');
        self::assertSame(CRegServPermission::DENEGADOS, $command->getRequiredPermission());
        self::assertSame('denegados.syntax', $command->getSyntaxKey());
        self::assertSame('denegados.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
    }

    #[Test]
    public function asksForTheDeniedQueueAndNeverForThePendingOne(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertSame(ChannelRegistrationStatus::Denied, $askedFor);
    }

    #[Test]
    public function saysSoOutLoudWhenNothingWasEverDenied(): void
    {
        $messages = [];
        $params = [];
        $askedFor = null;
        $command = $this->createCommand([], $askedFor);

        $command->execute($this->createContext($messages, $params));

        self::assertSame(['denegados.empty'], $messages);
    }

    #[Test]
    public function printsOneLinePerDenialAndClosesTheList(): void
    {
        $messages = [];
        $params = [];
        $command = $this->createCommand([self::row('#Viejo'), self::row('#Nuevo')]);

        $command->execute($this->createContext($messages, $params));

        self::assertSame(['denegados.line', 'denegados.line', 'denegados.end'], $messages);
    }

    #[Test]
    public function showsTheReasonThatMadeDENEGARRequireIt(): void
    {
        $messages = [];
        $params = [];
        $command = $this->createCommand([self::row('#Denegado', 'No cumple las normas')]);

        $command->execute($this->createContext($messages, $params));

        /** @var array<string, mixed> $line */
        $line = $params[0]['params'];

        self::assertSame('#Denegado', $line['%channel%']);
        self::assertSame('No cumple las normas', $line['%reason%']);
    }

    /**
     * DENEGAR makes the reason mandatory, but the entity stores it nullable, so the line has
     * to degrade to an empty reason rather than blow up on a record written another way.
     */
    #[Test]
    public function rendersAMissingReasonAsAnEmptyString(): void
    {
        $messages = [];
        $params = [];
        $command = $this->createCommand([self::row('#Denegado', null)]);

        $command->execute($this->createContext($messages, $params));

        /** @var array<string, mixed> $line */
        $line = $params[0]['params'];

        self::assertSame('', $line['%reason%']);
    }
}
