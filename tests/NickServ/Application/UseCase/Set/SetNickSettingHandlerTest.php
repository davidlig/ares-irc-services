<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Set;

use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailChangeMailSender;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionExemption;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\PendingEmailChangeStore;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\SessionLanguageTracker;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\Service\VhostValidator;
use App\NickServ\Application\UseCase\Set\SetNickSetting;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandler;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\NickServ\Application\UseCase\Set\SetNickSettingOutcome;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use App\NickServ\Domain\Entity\ForbiddenVhost;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(SetNickSettingHandler::class)]
#[CoversClass(SetNickSetting::class)]
#[CoversClass(SetNickSettingResult::class)]
#[CoversClass(SetNickSettingOutcome::class)]
#[CoversClass(SetNickSettingOption::class)]
#[CoversClass(NickOperationActor::class)]
final class SetNickSettingHandlerTest extends TestCase
{
    #[Test]
    public function rejectsProtectedOperatorTargets(): void
    {
        foreach (['root', 'ircop', 'service'] as $kind) {
            $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
            $exemption = $this->createStub(NickProtectionExemption::class);
            $exemption->method('isRootNickname')->willReturn('root' === $kind);
            $exemption->method('isServiceNickname')->willReturn('service' === $kind);
            $exemption->method('isIrcopNickId')->willReturn('ircop' === $kind);
            $account = $this->account();
            $repository->method('findByNick')->willReturn($account);
            $result = $this->handler($repository, exemption: $exemption)->handle(
                $this->input(SetNickSettingOption::Language, 'es', true),
            );

            self::assertSame(match ($kind) {
                'root' => SetNickSettingOutcome::TargetIsRoot,
                'ircop' => SetNickSettingOutcome::TargetIsIrcop,
                default => SetNickSettingOutcome::TargetIsService,
            }, $result->outcome);
        }
    }

    #[Test]
    public function handlesMissingAccountAndSessionLanguage(): void
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn(null);
        $languages = $this->createMock(SessionLanguageTracker::class);
        $languages->expects(self::once())->method('register')->with('UID1', 'es');
        $handler = $this->handler($repository, languages: $languages);

        self::assertSame(SetNickSettingOutcome::TargetNotRegistered, $handler->handle(
            $this->input(SetNickSettingOption::Password, 'secret'),
        )->outcome);
        self::assertSame(SetNickSettingOutcome::SessionLanguageChanged, $handler->handle(
            $this->input(SetNickSettingOption::Language, 'ES'),
        )->outcome);
        self::assertSame(SetNickSettingOutcome::MissingValue, $handler->handle(
            $this->input(SetNickSettingOption::Language, ''),
        )->outcome);
        self::assertSame(SetNickSettingOutcome::InvalidLanguage, $handler->handle(
            $this->input(SetNickSettingOption::Language, 'xx'),
        )->outcome);
    }

    #[Test]
    public function changesPasswordAndPublishesSemanticEvents(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('new-hash');
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::exactly(2))->method('publish');
        $handler = $this->handler($repository, hasher: $hasher, events: $events);

        self::assertSame(SetNickSettingOutcome::MissingValue, $handler->handle(
            $this->input(SetNickSettingOption::Password, ''),
        )->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle(
            $this->input(SetNickSettingOption::Password, 'secret'),
        )->outcome);
        self::assertSame('new-hash', $account->getPasswordHash());
    }

    #[Test]
    public function validatesAndChangesEmailInOperatorMode(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $handler = $this->handler($repository);

        self::assertSame(SetNickSettingOutcome::MissingValue, $handler->handle($this->input(SetNickSettingOption::Email, '', true))->outcome);
        self::assertSame(SetNickSettingOutcome::InvalidEmail, $handler->handle($this->input(SetNickSettingOption::Email, 'bad', true))->outcome);

        $other = $this->account('Other', 2);
        $repository->method('findByEmail')->willReturn($other);
        self::assertSame(SetNickSettingOutcome::EmailAlreadyUsed, $handler->handle(
            $this->input(SetNickSettingOption::Email, 'other@example.com', true),
        )->outcome);
        self::assertSame(SetNickSettingOutcome::EmailAlreadyUsed, $handler->handle(
            $this->input(SetNickSettingOption::Email, 'other@example.com'),
        )->outcome);
    }

    #[Test]
    public function changesEmailDirectlyAndRejectsAccountWithoutCurrentEmail(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $repository->method('findByEmail')->willReturn(null);
        $result = $this->handler($repository)->handle($this->input(SetNickSettingOption::Email, 'new@example.com', true));

        self::assertSame(SetNickSettingOutcome::Changed, $result->outcome);
        self::assertSame('new@example.com', $account->getEmail());

        $withoutEmail = $this->account('NoMail', 3);
        new ReflectionClass(RegisteredNick::class)->getProperty('email')->setValue($withoutEmail, null);
        self::assertSame(SetNickSettingOutcome::NoCurrentEmail, $this->handler(
            $this->repositoryReturning($withoutEmail),
        )->handle($this->input(SetNickSettingOption::Email, 'new@example.com'))->outcome);
    }

    #[Test]
    public function requestsAndConfirmsEmailChange(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $repository->method('findByEmail')->willReturn(null);
        $pending = $this->createMock(PendingEmailChangeStore::class);
        $pending->expects(self::once())->method('store')->with('TestNick', 'new@example.com', 'TOKEN', self::isInstanceOf(DateTimeImmutable::class));
        $tokens = $this->createStub(VerificationTokenGenerator::class);
        $tokens->method('generate')->willReturn('TOKEN');
        $mailer = $this->createMock(EmailChangeMailSender::class);
        $mailer->expects(self::once())->method('sendVerification');
        $handler = $this->handler($repository, pending: $pending, tokens: $tokens, mailer: $mailer);

        $requested = $handler->handle($this->input(SetNickSettingOption::Email, 'new@example.com'));
        self::assertSame(SetNickSettingOutcome::EmailConfirmationSent, $requested->outcome);

        $consume = $this->createStub(PendingEmailChangeStore::class);
        $consume->method('consume')->willReturnOnConsecutiveCalls(false, true);
        $confirmationHandler = $this->handler($repository, pending: $consume);
        self::assertSame(SetNickSettingOutcome::InvalidEmailToken, $confirmationHandler->handle(
            $this->input(SetNickSettingOption::Email, 'new@example.com WRONG'),
        )->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $confirmationHandler->handle(
            $this->input(SetNickSettingOption::Email, 'new@example.com RIGHT'),
        )->outcome);
    }

    #[Test]
    public function reportsEmailDeliveryFailure(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $repository->method('findByEmail')->willReturn(null);
        $tokens = $this->createStub(VerificationTokenGenerator::class);
        $tokens->method('generate')->willReturn('TOKEN');
        $mailer = $this->createStub(EmailChangeMailSender::class);
        $mailer->method('sendVerification')->willThrowException(new RuntimeException('mail failed'));

        self::assertSame(SetNickSettingOutcome::MailDeliveryFailed, $this->handler(
            $repository,
            tokens: $tokens,
            mailer: $mailer,
        )->handle($this->input(SetNickSettingOption::Email, 'new@example.com'))->outcome);
    }

    #[Test]
    public function changesLanguageAndTimezone(): void
    {
        $account = $this->account();
        $handler = $this->handler($this->repositoryReturning($account));

        self::assertSame(SetNickSettingOutcome::MissingValue, $handler->handle($this->input(SetNickSettingOption::Language, ''))->outcome);
        self::assertSame(SetNickSettingOutcome::InvalidLanguage, $handler->handle($this->input(SetNickSettingOption::Language, 'xx'))->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::Language, 'es'))->outcome);
        self::assertSame(SetNickSettingOutcome::MissingValue, $handler->handle($this->input(SetNickSettingOption::Timezone, ''))->outcome);
        self::assertSame(SetNickSettingOutcome::InvalidTimezone, $handler->handle($this->input(SetNickSettingOption::Timezone, 'Mars/Olympus'))->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::Timezone, 'Europe/Madrid'))->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::Timezone, 'OFF'))->outcome);
        self::assertNull($account->getTimezone());
    }

    #[Test]
    public function changesBooleanFlags(): void
    {
        $account = $this->account();
        $handler = $this->handler($this->repositoryReturning($account));

        self::assertSame(SetNickSettingOutcome::InvalidFlag, $handler->handle($this->input(SetNickSettingOption::PrivateMode, 'MAYBE'))->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::PrivateMode, 'ON'))->outcome);
        self::assertTrue($account->isPrivate());
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::PrivateMode, 'OFF'))->outcome);
        self::assertFalse($account->isPrivate());
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::MessageMode, 'ON'))->outcome);
        self::assertTrue($account->prefersPrivateMessages());
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::MessageMode, 'OFF'))->outcome);
        self::assertFalse($account->prefersPrivateMessages());
    }

    #[Test]
    public function validatesForcedForbiddenAndTakenVhosts(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $forced = $this->createStub(ForcedVhostCheckerInterface::class);
        $forced->method('hasForcedVhost')->willReturn(true);
        self::assertSame(SetNickSettingOutcome::ForcedVhost, $this->handler($repository, forced: $forced)->handle(
            $this->input(SetNickSettingOption::Vhost, 'valid'),
        )->outcome);

        self::assertSame(SetNickSettingOutcome::InvalidVhost, $this->handler($repository)->handle(
            $this->input(SetNickSettingOption::Vhost, '-invalid'),
        )->outcome);

        $forbidden = $this->createStub(ForbiddenVhostRepositoryInterface::class);
        $forbidden->method('findAll')->willReturn([ForbiddenVhost::create('*.blocked', null, new DateTimeImmutable())]);
        self::assertSame(SetNickSettingOutcome::InvalidVhost, $this->handler($repository, forbidden: $forbidden)->handle(
            $this->input(SetNickSettingOption::Vhost, 'foo.blocked'),
        )->outcome);

        $repository->method('findByVhost')->willReturn($this->account('Other', 2));
        self::assertSame(SetNickSettingOutcome::VhostTaken, $this->handler($repository)->handle(
            $this->input(SetNickSettingOption::Vhost, 'taken'),
        )->outcome);
    }

    #[Test]
    public function setsAndClearsOwnerVhostOnNetwork(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $repository->method('findByVhost')->willReturn(null);
        $network = $this->createMock(NickNetworkActions::class);
        $network->expects(self::exactly(2))->method('setUserVhost')->with('UID1', self::anything(), 'SID1');
        $handler = $this->handler($repository, network: $network, display: new VhostDisplayResolver('users'));

        $set = $handler->handle($this->input(SetNickSettingOption::Vhost, 'cool'));
        self::assertSame('cool.users', $set->value);
        $clear = $handler->handle($this->input(SetNickSettingOption::Vhost, ''));
        self::assertSame(SetNickSettingOutcome::Changed, $clear->outcome);
        self::assertNull($clear->value);
    }

    #[Test]
    public function operatorVhostTargetsOnlineUserAndIgnoresOfflineUser(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $repository->method('findByVhost')->willReturn(null);
        $lookup = $this->createStub(NickNetworkUserLookup::class);
        $lookup->method('findByNick')->willReturnOnConsecutiveCalls(
            new NetworkUser('TARGET', 'TestNick', 'i', 'h', 'c', 'ip', serverSid: 'REMOTE'),
            null,
        );
        $network = $this->createMock(NickNetworkActions::class);
        $network->expects(self::once())->method('setUserVhost')->with('TARGET', 'oper-vhost', 'REMOTE');
        $handler = $this->handler($repository, lookup: $lookup, network: $network);

        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::Vhost, 'oper-vhost', true))->outcome);
        self::assertSame(SetNickSettingOutcome::Changed, $handler->handle($this->input(SetNickSettingOption::Vhost, '', true))->outcome);
    }

    #[Test]
    public function treatsOffAsLiteralVhost(): void
    {
        $account = $this->account();
        $repository = $this->repositoryReturning($account);
        $repository->method('findByVhost')->willReturn(null);
        $network = $this->createMock(NickNetworkActions::class);
        $network->expects(self::once())->method('setUserVhost')->with('UID1', 'OFF', 'SID1');
        $handler = $this->handler($repository, network: $network);

        $result = $handler->handle($this->input(SetNickSettingOption::Vhost, 'OFF'));

        self::assertSame(SetNickSettingOutcome::Changed, $result->outcome);
        self::assertSame('OFF', $result->value);
    }

    private function handler(
        RegisteredNickRepositoryInterface $repository,
        ?NickProtectionExemption $exemption = null,
        ?PasswordHasher $hasher = null,
        ?NickServEventPublisher $events = null,
        ?PendingEmailChangeStore $pending = null,
        ?VerificationTokenGenerator $tokens = null,
        ?EmailChangeMailSender $mailer = null,
        ?SessionLanguageTracker $languages = null,
        ?NickNetworkUserLookup $lookup = null,
        ?NickNetworkActions $network = null,
        ?ForcedVhostCheckerInterface $forced = null,
        ?ForbiddenVhostRepositoryInterface $forbidden = null,
        ?VhostDisplayResolver $display = null,
    ): SetNickSettingHandler {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-09 12:00:00 UTC'));

        return new SetNickSettingHandler(
            $repository,
            new NickTargetValidator($exemption ?? $this->createStub(NickProtectionExemption::class), $repository),
            $hasher ?? $this->createStub(PasswordHasher::class),
            $events ?? $this->createStub(NickServEventPublisher::class),
            $clock,
            $pending ?? $this->createStub(PendingEmailChangeStore::class),
            $tokens ?? $this->createStub(VerificationTokenGenerator::class),
            $mailer ?? $this->createStub(EmailChangeMailSender::class),
            $languages ?? $this->createStub(SessionLanguageTracker::class),
            new VhostValidator(),
            $display ?? new VhostDisplayResolver(''),
            $lookup ?? $this->createStub(NickNetworkUserLookup::class),
            $network ?? $this->createStub(NickNetworkActions::class),
            $forced ?? $this->createStub(ForcedVhostCheckerInterface::class),
            $forbidden ?? $this->createStub(ForbiddenVhostRepositoryInterface::class),
        );
    }

    private function input(SetNickSettingOption $option, string $value, bool $operator = false): SetNickSetting
    {
        return new SetNickSetting(
            new NickOperationActor('Oper', 99, 'UID1', 'SID1', 'ident@host', '127.0.0.1'),
            'TestNick',
            $option,
            $value,
            $operator,
            'en',
        );
    }

    private function account(string $nickname = 'TestNick', int $id = 1): RegisteredNick
    {
        $account = RegisteredNick::createPending(
            $nickname,
            'hash',
            strtolower($nickname) . '@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );
        $account->activate();
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($account, $id);

        return $account;
    }

    private function repositoryReturning(RegisteredNick $account): RegisteredNickRepositoryInterface&Stub
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);

        return $repository;
    }
}
