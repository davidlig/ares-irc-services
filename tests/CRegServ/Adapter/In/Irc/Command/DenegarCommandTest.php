<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\DenegarCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistration;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\DenyChannelRegistration\DenyChannelRegistrationResult;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DenegarCommand::class)]
final class DenegarCommandTest extends TestCase
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
            'DENEGAR',
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

    private function createCommand(DenyChannelRegistrationResult $result): DenegarCommand
    {
        $handler = $this->createStub(DenyChannelRegistrationHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        return new DenegarCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $command = $this->createCommand(DenyChannelRegistrationResult::invalidChannel('#SitioChat'));

        self::assertSame('DENEGAR', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs(), 'The reason is mandatory, as in pulsar do_denegar.');
        self::assertSame(4, $command->getOrder());
        self::assertTrue($command->isOperOnly());
        self::assertSame(CRegServPermission::DENEGAR, $command->getRequiredPermission());
        self::assertSame('denegar.syntax', $command->getSyntaxKey());
        self::assertSame('denegar.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $command = $this->createCommand(DenyChannelRegistrationResult::invalidChannel('#SitioChat'));

        self::assertSame('denegar.help', $command->getHelpKey());
    }

    /**
     * @return array<string, array{DenyChannelRegistrationResult, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'denied' => [DenyChannelRegistrationResult::denied('#SitioChat', 'Spam'), 'denegar.denied'],
            'invalid channel' => [DenyChannelRegistrationResult::invalidChannel('SitioChat'), 'denegar.invalid_channel'],
            'nothing pending' => [DenyChannelRegistrationResult::noPendingRequest('#SitioChat'), 'denegar.no_pending'],
            'already approved' => [
                DenyChannelRegistrationResult::alreadyApproved('#SitioChat'),
                'denegar.already_approved',
            ],
            'already denied' => [DenyChannelRegistrationResult::alreadyDenied('#SitioChat'), 'denegar.already_denied'],
        ];
    }

    #[Test]
    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForTheUseCaseOutcome(
        DenyChannelRegistrationResult $result,
        string $expectedKey,
    ): void {
        $messages = [];
        $command = $this->createCommand($result);

        $command->execute($this->createContext(['#SitioChat', 'No', 'cumple', 'las', 'normas'], $messages));

        self::assertSame([$expectedKey], $messages);
    }

    #[Test]
    public function rejoinsEveryWordAfterTheChannelIntoOneMandatoryReason(): void
    {
        /** @var list<DenyChannelRegistration> $received */
        $received = [];
        $handler = $this->createMock(DenyChannelRegistrationHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(
                static function (DenyChannelRegistration $command) use (&$received): DenyChannelRegistrationResult {
                    $received[] = $command;

                    return DenyChannelRegistrationResult::denied($command->channelName, $command->reason);
                },
            );

        $command = new DenegarCommand($handler);
        $messages = [];
        $command->execute($this->createContext(['#SitioChat', 'No', 'cumple', 'las', 'normas'], $messages));

        self::assertCount(1, $received);
        self::assertSame('#SitioChat', $received[0]->channelName);
        self::assertSame('No cumple las normas', $received[0]->reason, 'The reason is everything after the channel.');
        self::assertSame('NetAdmin', $received[0]->denierNickname);
    }
}
