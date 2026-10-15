<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\AprobarCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistration;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationResult;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AprobarCommand::class)]
final class AprobarCommandTest extends TestCase
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
            'APROBAR',
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

    private function createCommand(ApproveChannelRegistrationResult $result): AprobarCommand
    {
        $handler = $this->createStub(ApproveChannelRegistrationHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        return new AprobarCommand($handler);
    }

    #[Test]
    public function metadataMatchesThePortedPulsarCommand(): void
    {
        $command = $this->createCommand(ApproveChannelRegistrationResult::invalidChannel('#SitioChat'));

        self::assertSame('APROBAR', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame(3, $command->getOrder());
        self::assertTrue($command->isOperOnly());
        self::assertSame(CRegServPermission::APROBAR, $command->getRequiredPermission());
        self::assertSame('aprobar.syntax', $command->getSyntaxKey());
        self::assertSame('aprobar.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
    }

    /**
     * @return array<string, array{ApproveChannelRegistrationResult, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'approved' => [ApproveChannelRegistrationResult::approved('#SitioChat', 'JuanJo_Jaen'), 'aprobar.approved'],
            'invalid channel' => [ApproveChannelRegistrationResult::invalidChannel('SitioChat'), 'aprobar.invalid_channel'],
            'nothing pending' => [ApproveChannelRegistrationResult::noPendingRequest('#SitioChat'), 'aprobar.no_pending'],
            'held by ChanServ' => [
                ApproveChannelRegistrationResult::alreadyRegisteredElsewhere('#SitioChat'),
                'aprobar.already_registered',
            ],
            'already approved' => [
                ApproveChannelRegistrationResult::alreadyApproved('#SitioChat', 'JuanJo_Jaen'),
                'aprobar.already_approved',
            ],
            'already denied' => [ApproveChannelRegistrationResult::alreadyDenied('#SitioChat'), 'aprobar.already_denied'],
            'registration failed' => [
                ApproveChannelRegistrationResult::registrationFailed('#SitioChat'),
                'aprobar.failed',
            ],
        ];
    }

    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForTheUseCaseOutcome(
        ApproveChannelRegistrationResult $result,
        string $expectedKey,
    ): void {
        $messages = [];
        $command = $this->createCommand($result);

        $command->execute($this->createContext(['#SitioChat'], $messages));

        self::assertSame([$expectedKey], $messages);
    }

    #[Test]
    public function forwardsTheChannelAndTheApprovingOperToTheUseCase(): void
    {
        /** @var list<ApproveChannelRegistration> $received */
        $received = [];
        $handler = $this->createMock(ApproveChannelRegistrationHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(
                static function (ApproveChannelRegistration $command) use (&$received): ApproveChannelRegistrationResult {
                    $received[] = $command;

                    return ApproveChannelRegistrationResult::approved($command->channelName, 'JuanJo_Jaen');
                },
            );

        $command = new AprobarCommand($handler);
        $messages = [];
        $command->execute($this->createContext(['#SitioChat'], $messages));

        self::assertCount(1, $received);
        self::assertSame('#SitioChat', $received[0]->channelName);
        self::assertSame('NetAdmin', $received[0]->approverNickname);
    }
}
