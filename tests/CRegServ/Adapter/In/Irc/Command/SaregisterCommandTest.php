<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\CRegServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\CRegServ\Application\Security\CRegServPermission;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The ported legacy ChanServ command. It keeps its force semantics — no channel
 * membership, no rank, no throttle — but now lives behind CReG's own permission and
 * answers in the cregserv translation domain.
 */
#[CoversClass(SaregisterCommand::class)]
final class SaregisterCommandTest extends TestCase
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

    private function defaultSender(): SenderView
    {
        return new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip', true, true);
    }

    private function defaultAccount(): CRegServAccountView
    {
        return new CRegServAccountView(5, 'NetAdmin', 'en');
    }

    /**
     * Sender and account are required and may legitimately be null: both are passed
     * explicitly so a null cannot be silently replaced by a default.
     *
     * @param string[]     $args
     * @param list<string> $messages
     */
    private function createContext(
        array $args,
        array &$messages,
        ?SenderView $sender,
        ?CRegServAccountView $senderAccount,
    ): CRegServContext {
        return new CRegServContext(
            $sender,
            $senderAccount,
            'SAREGISTER',
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

    #[Test]
    public function metadataMatchesThePortedLegacyCommand(): void
    {
        $command = new SaregisterCommand($this->createStub(ChannelRegistration::class));

        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame(8, $command->getOrder());
        self::assertTrue($command->isOperOnly(), 'It stays restricted to operators, as it was in ChanServ.');
        self::assertSame(CRegServPermission::SAREGISTER, $command->getRequiredPermission());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $command = new SaregisterCommand($this->createStub(ChannelRegistration::class));

        self::assertSame('saregister.help', $command->getHelpKey());
    }

    /**
     * @return array<string, array{ChannelRegistrationOutcome, string}>
     */
    public static function outcomeReplyProvider(): array
    {
        return [
            'registered' => [ChannelRegistrationOutcome::Registered, 'saregister.done'],
            'pending deletion' => [ChannelRegistrationOutcome::PendingDeletion, 'saregister.pending_deletion'],
            'already registered' => [ChannelRegistrationOutcome::AlreadyRegistered, 'saregister.already_registered'],
            'failed' => [ChannelRegistrationOutcome::Failed, 'saregister.failed'],
        ];
    }

    #[Test]
    #[DataProvider('outcomeReplyProvider')]
    public function repliesTheMatchingMessageForThePortOutcome(
        ChannelRegistrationOutcome $outcome,
        string $expectedKey,
    ): void {
        $messages = [];
        $port = $this->createStub(ChannelRegistration::class);
        $port->method('registerApproved')->willReturn($outcome);

        $command = new SaregisterCommand($port);
        $command->execute($this->createContext(
            ['#SitioChat', 'descripción'],
            $messages,
            $this->defaultSender(),
            $this->defaultAccount(),
        ));

        self::assertSame([$expectedKey], $messages);
    }

    #[Test]
    public function forwardsAForcedRegistrationBearingTheOperatorAccount(): void
    {
        $messages = [];
        $port = $this->createMock(ChannelRegistration::class);
        $port->expects(self::once())
            ->method('registerApproved')
            ->with(
                '#SitioChat',
                'La descripción con espacios',
                5,
                'NetAdmin',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(ChannelRegistrationOutcome::Registered);

        $command = new SaregisterCommand($port);
        $command->execute($this->createContext(
            ['#SitioChat', 'La', 'descripción', 'con', 'espacios'],
            $messages,
            $this->defaultSender(),
            $this->defaultAccount(),
        ));

        self::assertSame(['saregister.done'], $messages);
    }

    #[Test]
    public function rejectsAChannelNameWithoutAHashBeforeConsultingThePort(): void
    {
        $messages = [];
        $port = $this->createMock(ChannelRegistration::class);
        $port->expects(self::never())->method('registerApproved');

        $command = new SaregisterCommand($port);
        $command->execute($this->createContext(
            ['SitioChat', 'descripción'],
            $messages,
            $this->defaultSender(),
            $this->defaultAccount(),
        ));

        self::assertSame(['saregister.invalid_channel'], $messages);
    }

    #[Test]
    public function repliesNotIdentifiedWhenTheOperatorHasNoAccount(): void
    {
        $messages = [];
        $port = $this->createMock(ChannelRegistration::class);
        $port->expects(self::never())->method('registerApproved');

        $command = new SaregisterCommand($port);
        $command->execute($this->createContext(
            ['#SitioChat', 'descripción'],
            $messages,
            sender: $this->defaultSender(),
            senderAccount: null,
        ));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function neverRegistersWithoutASender(): void
    {
        $messages = [];
        $port = $this->createMock(ChannelRegistration::class);
        $port->expects(self::never())->method('registerApproved');

        $command = new SaregisterCommand($port);
        $command->execute($this->createContext(
            ['#SitioChat', 'descripción'],
            $messages,
            sender: null,
            senderAccount: null,
        ));
    }
}
