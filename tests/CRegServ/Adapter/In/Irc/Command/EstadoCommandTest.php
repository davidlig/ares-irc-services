<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\EstadoCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusHandlerInterface;
use App\CRegServ\Application\UseCase\GetChannelRegistrationStatus\GetChannelRegistrationStatusResult;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(EstadoCommand::class)]
final class EstadoCommandTest extends TestCase
{
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

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
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
     * @param string[] $args
     */
    private function createContext(
        ?CRegServAccountView $senderAccount,
        array $args,
        CRegServNotifierInterface $notifier,
    ): CRegServContext {
        return new CRegServContext(
            new SenderView('UID1', 'JuanJo_Jaen', 'i', 'h', 'c', 'ip'),
            $senderAccount,
            'ESTADO',
            $args,
            $notifier,
            $this->createTranslator(),
            'en',
            'UTC',
            'NOTICE',
            new CRegServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }

    private function createCommand(GetChannelRegistrationStatusResult $result): EstadoCommand
    {
        $handler = $this->createStub(GetChannelRegistrationStatusHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        return new EstadoCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $command = $this->createCommand(GetChannelRegistrationStatusResult::noRequest());

        self::assertSame('ESTADO', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame(2, $command->getOrder());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    /**
     * @return array<string, array{GetChannelRegistrationStatusResult, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'registered' => [GetChannelRegistrationStatusResult::registered(), 'estado.registered'],
            'pending' => [
                GetChannelRegistrationStatusResult::pending(new DateTimeImmutable('2026-10-15 10:00:00')),
                'estado.pending',
            ],
            'denied' => [
                GetChannelRegistrationStatusResult::denied('Canal de spam', new DateTimeImmutable('2026-10-14 11:00:00')),
                'estado.denied',
            ],
            'no request' => [GetChannelRegistrationStatusResult::noRequest(), 'estado.no_request'],
        ];
    }

    #[Test]
    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForTheUseCaseOutcome(
        GetChannelRegistrationStatusResult $result,
        string $expectedKey,
    ): void {
        $messages = [];
        $command = $this->createCommand($result);

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat'],
            $this->createNotifier($messages),
        ));

        self::assertSame([$expectedKey], $messages);
    }

    #[Test]
    public function repliesNotIdentifiedWhenTheSenderHasNoAccount(): void
    {
        $messages = [];
        $handler = $this->createMock(GetChannelRegistrationStatusHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new EstadoCommand($handler);

        $command->execute($this->createContext(null, ['#SitioChat'], $this->createNotifier($messages)));

        self::assertSame(['error.not_identified'], $messages);
    }
}
