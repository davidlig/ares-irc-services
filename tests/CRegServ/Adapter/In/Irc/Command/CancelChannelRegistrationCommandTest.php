<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\CancelChannelRegistrationCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistration;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\CancelChannelRegistration\CancelChannelRegistrationResult;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CancelChannelRegistrationCommand::class)]
final class CancelChannelRegistrationCommandTest extends TestCase
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
            'CANCEL',
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
     * @param list<string>                    $messages
     * @param list<CancelChannelRegistration> $captured
     */
    private function createCommand(
        CancelChannelRegistrationResult $result,
        array &$messages,
        array &$captured,
    ): CancelChannelRegistrationCommand {
        $handler = $this->createStub(CancelChannelRegistrationHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (CancelChannelRegistration $command) use (&$captured, $result): CancelChannelRegistrationResult {
                $captured[] = $command;

                return $result;
            },
        );

        return new CancelChannelRegistrationCommand($handler);
    }

    #[Test]
    public function metadataMatchesTheUserFacingConvention(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        self::assertSame('CANCEL', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame(3, $command->getOrder());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        self::assertSame('cancel.syntax', $command->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        self::assertSame('cancel.help', $command->getHelpKey());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        self::assertSame('cancel.short', $command->getShortDescKey());
    }

    #[Test]
    public function getExampleKeyReturnsCorrectKey(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        self::assertSame('cancel.example', $command->getExampleKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        self::assertSame([], $command->getSubCommandHelp());
    }

    /**
     * @return array<string, array{CancelChannelRegistrationResult, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'cancelled' => [CancelChannelRegistrationResult::cancelled('#SitioChat'), 'cancel.cancelled'],
            'invalid channel' => [CancelChannelRegistrationResult::invalidChannel(), 'cancel.invalid_channel'],
            'no request' => [CancelChannelRegistrationResult::noRequest('#SitioChat'), 'cancel.no_request'],
            'not owner' => [CancelChannelRegistrationResult::notOwner('#SitioChat'), 'cancel.not_yours'],
            'not pending' => [CancelChannelRegistrationResult::notPending('#SitioChat'), 'cancel.not_pending'],
        ];
    }

    #[Test]
    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForTheUseCaseOutcome(
        CancelChannelRegistrationResult $result,
        string $expectedKey,
    ): void {
        $messages = [];
        $captured = [];
        $command = $this->createCommand($result, $messages, $captured);

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat'],
            $this->createNotifier($messages),
        ));

        self::assertSame([$expectedKey], $messages);
        self::assertCount(1, $captured);
        self::assertSame('#SitioChat', $captured[0]->channelName);
        self::assertSame(5, $captured[0]->requesterAccountId);
    }

    #[Test]
    public function repliesNotIdentifiedWhenTheSenderHasNoAccount(): void
    {
        $messages = [];
        $captured = [];
        $handler = $this->createMock(CancelChannelRegistrationHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new CancelChannelRegistrationCommand($handler);

        $command->execute($this->createContext(null, ['#SitioChat'], $this->createNotifier($messages)));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function stampsTheOccurrenceTimeForTheUseCase(): void
    {
        $messages = [];
        $captured = [];
        $command = $this->createCommand(CancelChannelRegistrationResult::cancelled('#SitioChat'), $messages, $captured);

        $command->execute($this->createContext(
            new CRegServAccountView(5, 'JuanJo_Jaen', 'en'),
            ['#SitioChat'],
            $this->createNotifier($messages),
        ));

        self::assertCount(1, $captured);
        self::assertLessThanOrEqual(5, abs(time() - $captured[0]->occurredAt->getTimestamp()));
    }
}
