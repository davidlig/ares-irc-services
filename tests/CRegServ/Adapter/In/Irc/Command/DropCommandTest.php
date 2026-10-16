<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\DropCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistration;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\DropChannelRegistration\DropChannelRegistrationResult;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DropCommand::class)]
final class DropCommandTest extends TestCase
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
     * @param string[]     $args
     * @param list<string> $messages
     */
    private function createContext(array $args, array &$messages): CRegServContext
    {
        return new CRegServContext(
            new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip'),
            new CRegServAccountView(5, 'NetAdmin', 'en'),
            'DROP',
            $args,
            $this->createNotifier($messages),
            $this->createTranslator(),
            'en',
            'UTC',
            'NOTICE',
            new CRegServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }

    private function createCommand(DropChannelRegistrationResult $result): DropCommand
    {
        $handler = $this->createStub(DropChannelRegistrationHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        return new DropCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $command = $this->createCommand(DropChannelRegistrationResult::noRequest('#SitioChat'));

        self::assertSame('DROP', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame(7, $command->getOrder());
        self::assertTrue($command->isOperOnly(), 'pulsar guards it with is_services_admin, an IRCop permission here.');
        self::assertSame(CRegServPermission::DROP, $command->getRequiredPermission());
        self::assertSame('drop.syntax', $command->getSyntaxKey());
        self::assertSame('drop.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $command = $this->createCommand(DropChannelRegistrationResult::noRequest('#SitioChat'));

        self::assertSame('drop.help', $command->getHelpKey());
    }

    /**
     * @return array<string, array{DropChannelRegistrationResult, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'dropped' => [DropChannelRegistrationResult::dropped('#SitioChat'), 'drop.dropped'],
            'invalid channel' => [DropChannelRegistrationResult::invalidChannel('SitioChat'), 'drop.invalid_channel'],
            'nothing to drop' => [DropChannelRegistrationResult::noRequest('#SitioChat'), 'drop.no_request'],
        ];
    }

    #[Test]
    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForTheUseCaseOutcome(
        DropChannelRegistrationResult $result,
        string $expectedKey,
    ): void {
        $messages = [];
        $command = $this->createCommand($result);

        $command->execute($this->createContext(['#SitioChat'], $messages));

        self::assertSame([$expectedKey], $messages);
    }

    #[Test]
    public function forwardsTheChannelAsTypedToTheUseCase(): void
    {
        /** @var list<DropChannelRegistration> $received */
        $received = [];
        $handler = $this->createMock(DropChannelRegistrationHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(
                static function (DropChannelRegistration $command) use (&$received): DropChannelRegistrationResult {
                    $received[] = $command;

                    return DropChannelRegistrationResult::dropped($command->channelName);
                },
            );

        $command = new DropCommand($handler);
        $messages = [];
        $command->execute($this->createContext(['#SITIOCHAT'], $messages));

        self::assertCount(1, $received);
        self::assertSame('#SITIOCHAT', $received[0]->channelName, 'The lookup is case-insensitive, so the command does not touch it.');
    }
}
