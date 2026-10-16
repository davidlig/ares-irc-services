<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\RegistraCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistration;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationResult;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RegistraCommand::class)]
final class RegistraCommandTest extends TestCase
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
            'REGISTRA',
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

    /**
     * @param list<string>                     $messages
     * @param list<RequestChannelRegistration> $captured
     */
    private function createCommand(
        RequestChannelRegistrationResult $result,
        array &$messages,
        array &$captured,
    ): RegistraCommand {
        $handler = $this->createStub(RequestChannelRegistrationHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (RequestChannelRegistration $command) use (&$captured, $result): RequestChannelRegistrationResult {
                $captured[] = $command;

                return $result;
            },
        );

        return new RegistraCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        self::assertSame('REGISTRA', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame(1, $command->getOrder());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        self::assertSame('registra.syntax', $command->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        self::assertSame('registra.help', $command->getHelpKey());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        self::assertSame('registra.short', $command->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        self::assertSame([], $command->getSubCommandHelp());
    }

    #[Test]
    public function getExampleKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        self::assertSame('registra.example', $command->getExampleKey());
    }

    #[Test]
    public function repliesNotIdentifiedWhenTheSenderHasNoAccount(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        $command->execute($this->createContext(null, ['#SitioChat', 'Canal'], $this->createNotifier($messages)));

        self::assertSame(['error.not_identified'], $messages);
        self::assertSame([], $captured);
    }

    #[Test]
    public function passesTheWholeDescriptionToTheUseCase(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat', 'Canal', 'de', 'la', 'comunidad'],
            $this->createNotifier($messages),
        ));

        self::assertSame(['registra.requested'], $messages);
        self::assertCount(1, $captured);
        self::assertSame('#SitioChat', $captured[0]->channelName);
        self::assertSame('Canal de la comunidad', $captured[0]->description);
        self::assertSame(5, $captured[0]->requesterAccountId);
        self::assertSame('JuanJo_Jaen', $captured[0]->requesterNickname);
    }

    /**
     * @return array<string, array{RequestChannelRegistrationResult, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'invalid channel' => [RequestChannelRegistrationResult::invalidChannel(), 'registra.invalid_channel'],
            'channel too long' => [RequestChannelRegistrationResult::channelTooLong(), 'registra.channel_too_long'],
            'description too long' => [
                RequestChannelRegistrationResult::descriptionTooLong(),
                'registra.description_too_long',
            ],
            'already registered' => [RequestChannelRegistrationResult::alreadyRegistered(), 'registra.already_registered'],
            'pending deletion' => [RequestChannelRegistrationResult::pendingDeletion(), 'registra.pending_deletion'],
            'duplicate request' => [RequestChannelRegistrationResult::duplicateRequest(), 'registra.duplicate'],
            'request denied' => [RequestChannelRegistrationResult::requestDenied(), 'registra.denied'],
        ];
    }

    #[Test]
    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForTheUseCaseOutcome(
        RequestChannelRegistrationResult $result,
        string $expectedKey,
    ): void {
        $messages = [];
        $captured = [];
        $command = $this->createCommand($result, $messages, $captured);

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat', 'Canal'],
            $this->createNotifier($messages),
        ));

        self::assertSame([$expectedKey], $messages);
    }

    #[Test]
    public function repliesTheThrottleCooldownInMinutes(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(
            RequestChannelRegistrationResult::throttled(1320),
            $messages,
            $captured,
        );

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat', 'Canal'],
            $this->createNotifier($messages),
        ));

        self::assertSame(['registra.throttled'], $messages);
    }

    #[Test]
    public function stampsTheOccurrenceTimeForTheUseCase(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(RequestChannelRegistrationResult::requested(), $messages, $captured);

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat', 'Canal'],
            $this->createNotifier($messages),
        ));

        self::assertCount(1, $captured);
        self::assertLessThanOrEqual(5, abs(time() - $captured[0]->occurredAt->getTimestamp()));
    }
}
