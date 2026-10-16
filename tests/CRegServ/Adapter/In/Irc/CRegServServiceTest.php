<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc;

use App\CRegServ\Adapter\In\Irc\Command\AprobarCommand;
use App\CRegServ\Adapter\In\Irc\Command\RegistraCommand;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationContextInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Adapter\In\Irc\CRegServService;
use App\CRegServ\Adapter\In\Irc\CRegServUserPresentationPreferences;
use App\CRegServ\Application\Port\Out\CRegServUserAccountPort;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\CRegServ\Fake\OperOnlyCommandDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CRegServService::class)]
final class CRegServServiceTest extends TestCase
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
     * An oper-only command that declares NO permission at all: the oper gate alone must stop it.
     */
    private function createOperOnlyCommand(): OperOnlyCommandDouble
    {
        return new OperOnlyCommandDouble();
    }

    private function createService(
        CRegServCommandInterface $command,
        CRegServNotifierInterface $notifier,
        ?CRegServAuthorizationCheckerInterface $authorizationChecker = null,
        ?LoggerInterface $logger = null,
    ): CRegServService {
        return new CRegServService(
            commandRegistry: new CRegServCommandRegistry([$command]),
            userAccountPort: $this->createStub(CRegServUserAccountPort::class),
            languageResolver: $this->createPreferencesStub(),
            notifier: $notifier,
            messageTypeResolver: $this->createPreferencesStub(),
            translator: $this->createTranslator(),
            serviceNicks: $this->createServiceNicks(),
            authorizationContext: $this->createStub(CRegServAuthorizationContextInterface::class),
            // Default stub grants everything, so only the oper gate can be what rejects the command.
            authorizationChecker: $authorizationChecker ?? $this->createGrantedChecker(),
            eventDispatcher: $this->createStub(EventBusInterface::class),
            logger: $logger ?? new NullLogger(),
        );
    }

    private function createPreferencesStub(): CRegServUserPresentationPreferences
    {
        $preferences = $this->createStub(CRegServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('en');
        $preferences->method('prefersPrivateMessages')->willReturn(false);

        return $preferences;
    }

    private function createGrantedChecker(): CRegServAuthorizationCheckerInterface
    {
        $checker = $this->createStub(CRegServAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);

        return $checker;
    }

    private function createDenyingChecker(): CRegServAuthorizationCheckerInterface
    {
        $checker = $this->createStub(CRegServAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);

        return $checker;
    }

    #[Test]
    public function operOnlyCommandIsRejectedForNonOperEvenWhenItDeclaresNoPermission(): void
    {
        $command = $this->createOperOnlyCommand();
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages));

        $service->dispatch('APROBAR #canal', new SenderView('UID1', 'JuanJo_Jaen', 'i', 'h', 'c', 'ip'));

        self::assertFalse($command->executed, 'An oper-only command must never run for a non-oper sender.');
        self::assertSame(['error.permission_denied'], $messages);
    }

    #[Test]
    public function operOnlyCommandIsExecutedForOper(): void
    {
        $command = $this->createOperOnlyCommand();
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages));

        $service->dispatch(
            'APROBAR #canal',
            new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true),
        );

        self::assertTrue($command->executed);
        self::assertSame([], $messages);
    }

    #[Test]
    public function emptyCommandLineIsIgnored(): void
    {
        $messages = [];
        $service = $this->createService($this->createOperOnlyCommand(), $this->createNotifier($messages));

        $service->dispatch('   ', new SenderView('UID1', 'SenderNick', 'i', 'h', 'c', 'ip'));

        self::assertSame([], $messages);
    }

    #[Test]
    public function unknownCommandRepliesWithUnknownCommandNotice(): void
    {
        $messages = [];
        $service = $this->createService($this->createOperOnlyCommand(), $this->createNotifier($messages));

        $service->dispatch('NOCOMANDO #canal', new SenderView('UID1', 'SenderNick', 'i', 'h', 'c', 'ip'));

        self::assertSame(['unknown_command'], $messages);
    }

    #[Test]
    public function missingIdentifiedPermissionRepliesNotIdentified(): void
    {
        $command = new RegistraCommand($this->createStub(RequestChannelRegistrationHandlerInterface::class));
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages), $this->createDenyingChecker());

        $service->dispatch('REGISTRA #canal', new SenderView('UID1', 'SenderNick', 'i', 'h', 'c', 'ip'));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function missingIrcopPermissionRepliesPermissionDenied(): void
    {
        $command = new AprobarCommand($this->createStub(ApproveChannelRegistrationHandlerInterface::class));
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages), $this->createDenyingChecker());

        $service->dispatch(
            'APROBAR #canal',
            new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true),
        );

        self::assertSame(['error.permission_denied'], $messages);
    }

    #[Test]
    public function missingArgumentsReplyWithSyntaxError(): void
    {
        $command = $this->createOperOnlyCommand();
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages));

        $service->dispatch('APROBAR', new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip', isOper: true));

        self::assertFalse($command->executed);
        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function commandFailureIsLoggedAndRethrown(): void
    {
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message, array $context = []) use (&$errors): void {
                $errors[] = [$message, $context];
            },
        );
        $command = new class implements CRegServCommandInterface {
            public function getName(): string
            {
                return 'FALLA';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'falla.syntax';
            }

            public function getHelpKey(): string
            {
                return 'falla.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'falla.short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(CRegServContext $context): ?CommandOutcome
            {
                throw new RuntimeException('boom');
            }
        };
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages), logger: $logger);

        try {
            $service->dispatch('FALLA', new SenderView('UID1', 'SenderNick', 'i', 'h', 'c', 'ip'));
            self::fail('The dispatch must rethrow the command failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertCount(1, $errors);
        self::assertSame('CRegServ dispatch error: boom', $errors[0][0]);
        self::assertArrayHasKey('exception', $errors[0][1]);
        self::assertSame('UID1', $errors[0][1]['sender']);
        self::assertSame([], $messages);
    }
}
