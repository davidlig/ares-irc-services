<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\HelpCommand;
use App\NickServ\Adapter\In\Irc\Command\PasswordPolicyPresentation;
use App\NickServ\Adapter\In\Irc\Command\RegisterCommand;
use App\NickServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\NickServ\Adapter\In\Irc\Command\SasetCommand;
use App\NickServ\Adapter\In\Irc\Command\SetCommand;
use App\NickServ\Adapter\In\Irc\Command\SetNickSettingPresentation;
use App\NickServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\In\Irc\NickServService;
use App\NickServ\Adapter\In\Irc\TimezoneHelpProvider;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Adapter\Out\User\UserLanguageResolver;
use App\NickServ\Adapter\Out\User\UserMessageTypeResolver;
use App\NickServ\Application\Port\In\RescueTokenValidator;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\NicknamePolicyInterface;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionExemption;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RegistrationThrottle;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\SaregisterMailSender;
use App\NickServ\Application\Port\Out\SessionLanguageTracker;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\Service\PasswordPolicy;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\Service\VhostValidator;
use App\NickServ\Application\UseCase\Register\RegisterNickHandler;
use App\NickServ\Application\UseCase\Register\RegisterNickResult;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickHandler;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickResult;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandler;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Translation\Translator;

use function dirname;
use function hash;
use function implode;
use function serialize;
use function sprintf;
use function str_repeat;

#[CoversClass(PasswordPolicyPresentation::class)]
#[CoversClass(RegisterNickHandler::class)]
#[CoversClass(RegisterNickResult::class)]
#[CoversClass(SaregisterNickHandler::class)]
#[CoversClass(SaregisterNickResult::class)]
#[CoversClass(SetNickSettingHandler::class)]
#[CoversClass(SetNickSettingResult::class)]
#[CoversClass(RegisterCommand::class)]
#[CoversClass(SaregisterCommand::class)]
#[CoversClass(SetCommand::class)]
#[CoversClass(SasetCommand::class)]
#[CoversClass(SetNickSettingPresentation::class)]
final class PasswordPolicyRoutingTest extends TestCase
{
    #[DataProvider('commands')]
    public function testPolicyAtPublicRoute(string $command, string $complexity, string $password, int $minimum, bool $accepted): void
    {
        $policy = new PasswordPolicy($minimum, $complexity);
        $messages = [];
        [$service, $account, $writes] = $this->service($policy, $messages, $accepted, $password);
        $before = hash('sha256', serialize($account));
        $service->dispatch(sprintf($command, $password), $this->sender());
        self::assertNotEmpty($messages);
        $output = implode("\n", $messages);
        if (!$accepted) {
            self::assertSame($before, hash('sha256', serialize($account)));
            self::assertSame(0, $writes->count);
            self::assertStringContainsString((string) $minimum, $output);
            self::assertStringNotContainsString($password, $output);
            self::assertStringNotContainsString('password_policy.', $output);
            if ('high' === $complexity) {
                self::assertStringContainsString(PasswordPolicy::SYMBOLS, $output);
            }
        } else {
            self::assertSame(1, $writes->count);
            self::assertStringNotContainsString('password_policy.', $output);
        }
    }

    /** @return iterable<array{string, string, string, int, bool}> */
    public static function commands(): iterable
    {
        foreach (['REGISTER %s', 'SAREGISTER Target %s', 'SET PASSWORD %s', 'SASET Target PASSWORD %s'] as $command) {
            yield [$command, 'none', 'abcdefg', 8, false];
            yield [$command, 'none', 'abcdefgh', 8, true];
            yield [$command, 'none', 'Abcdef1!ñ', 8, true];
            yield [$command, 'none', '[]{}\\^~123', 8, true];
            yield [$command, 'none', 'abcdefghijk', 11, true];
            yield [$command, 'none', 'abcdefghij', 11, false];
            yield [$command, 'low', 'abcdefgh1', 9, true];
            yield [$command, 'low', 'abcdefghi', 9, false];
            yield [$command, 'mid', 'Abcdefgh1', 9, true];
            yield [$command, 'mid', 'abcdefgh1', 9, false];
            yield [$command, 'high', 'Abcdefg1!', 9, true];
            yield [$command, 'high', 'Abcdefg1é', 9, false];
        }
    }

    #[DataProvider('helpCommands')]
    public function testSpanishHelpUsesConfiguredPolicy(string $command, string $complexity): void
    {
        $messages = [];
        [$service] = $this->service(new PasswordPolicy(17, $complexity), $messages, null);
        $service->dispatch($command, $this->sender());
        $output = implode("\n", $messages);
        self::assertStringContainsString('17', $output);
        self::assertStringContainsString($complexity, $output);
        self::assertStringNotContainsString('%password_', $output);
        self::assertStringNotContainsString('password_policy.requirements', $output);
        if ('high' === $complexity) {
            self::assertStringContainsString(PasswordPolicy::SYMBOLS, $output);
        }
        if ('none' === $complexity) {
            self::assertStringContainsString('Sin requisitos de composición', $output);
        }
    }

    /** @return iterable<array{string, string}> */
    public static function helpCommands(): iterable
    {
        foreach (['HELP REGISTER', 'HELP SAREGISTER', 'HELP SET PASSWORD', 'HELP SASET PASSWORD'] as $command) {
            foreach (['none', 'low', 'mid', 'high'] as $complexity) {
                yield [$command, $complexity];
            }
        }
    }

    /**
     * @param list<string> $messages
     *
     * @return array{NickServService, RegisteredNick, object{count: int}}
     */
    private function service(PasswordPolicy $policy, array &$messages, ?bool $accepted, string $expectedPassword = ''): array
    {
        $account = RegisteredNick::createActive('Target', 'old-hash', null, 'es', new DateTimeImmutable(), str_repeat('a', 64));
        new ReflectionProperty($account, 'id')->setValue($account, 1);
        $writes = new class {
            public int $count = 0;
        };
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);
        $repository->method('save')->willReturnCallback(static function () use ($writes): void { ++$writes->count; });
        $registrationRepo = $this->createStub(RegisterNickRepository::class);
        $registrationRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use ($writes): void {
            ++$writes->count;
            new ReflectionProperty($nick, 'id')->setValue($nick, 2);
        });
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(true === $accepted ? self::once() : self::never())->method('hash')->with($expectedPassword)->willReturn('new-hash');
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-11-03T18:00:00Z'));
        $mode = $this->createStub(RegistrationModeQuery::class);
        $mode->method('current')->willReturn(RegistrationMode::Token);
        if (false === $accepted) {
            $tokens = $this->createMock(RescueTokenCodec::class);
            $tokens->expects(self::never())->method('generate');
        } else {
            $tokens = $this->createStub(RescueTokenCodec::class);
            $tokens->method('generate')->willReturn('12345678-1234-4234-8234-123456789abc');
        }
        $tokens->method('hash')->willReturn(str_repeat('b', 64));
        $email = $this->createStub(EmailDomainPolicy::class);
        if (false === $accepted) {
            $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
            $historyRepo->expects(self::never())->method('save');
            $eventPublisher = $this->createMock(RegistrationEventPublisher::class);
            $eventPublisher->expects(self::never())->method('publish');
            $setEvents = $this->createMock(NickServEventPublisher::class);
            $setEvents->expects(self::never())->method('publish');
        } else {
            $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
            $eventPublisher = $this->createStub(RegistrationEventPublisher::class);
            $setEvents = $this->createStub(NickServEventPublisher::class);
        }
        $nickname = $this->createStub(NicknamePolicyInterface::class);
        $nickname->method('isValid')->willReturn(true);
        $registerMail = $this->createMock(RegistrationMailSender::class);
        $registerMail->expects(self::never())->method('sendVerification');
        $staffMail = $this->createMock(SaregisterMailSender::class);
        $staffMail->expects(self::never())->method('sendRescueToken');
        if (false === $accepted) {
            $throttle = $this->createMock(RegistrationThrottle::class);
            $throttle->expects(self::never())->method('recordAttempt');
            $store = $this->createMock(TemporaryRescueTokenStore::class);
            $store->expects(self::never())->method('replace');
            $store->expects(self::never())->method('prune');
        } else {
            $throttle = $this->createStub(RegistrationThrottle::class);
            $store = $this->createStub(TemporaryRescueTokenStore::class);
        }
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $register = new RegisterNickHandler($email, $registrationRepo, $hasher, $tokens, $clock, $store, $mode, $throttle, $registerMail, 0, passwordPolicy: $policy);
        $saregister = new SaregisterNickHandler($registrationRepo, $hasher, $tokens, $clock, $mode, $eventPublisher, new NickHistoryService($historyRepo), $email, $staffMail, $nickname, passwordPolicy: $policy);
        $set = new SetNickSettingHandler(
            $email,
            $repository,
            new NickTargetValidator($this->createStub(NickProtectionExemption::class), $repository),
            $hasher,
            $setEvents,
            $clock,
            $this->createStub(RescueTokenValidator::class),
            $this->createStub(SessionLanguageTracker::class),
            new VhostValidator(),
            new VhostDisplayResolver(''),
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $this->createStub(ForbiddenVhostRepositoryInterface::class),
            $policy
        );
        $operatorAccess = $this->createStub(NickServOperatorAccess::class);
        $operatorAccess->method('isRoot')->willReturn(true);
        $operatorAccess->method('hasPermission')->willReturn(true);
        $registry = new NickServCommandRegistry([
            new RegisterCommand($register, 3600, $policy), new SaregisterCommand($saregister, $policy),
            new SetCommand($set, $policy), new SasetCommand($set, passwordPolicy: $policy),
            new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), $operatorAccess),
        ]);
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);
        $translator = new Translator('es');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');
        $nicks = new ServiceNicknameRegistry([$provider]);

        return [new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $auth,
            $registry,
            $repository,
            new UserLanguageResolver($repository, new SessionLanguageRegistry(), 'es'),
            $notifier,
            new UserMessageTypeResolver($repository),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $nicks,
            $this->createStub(EventBusInterface::class)
        ), $account, $writes];
    }

    private function sender(): SenderView
    {
        return new SenderView('UID1', 'Target', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
    }
}
