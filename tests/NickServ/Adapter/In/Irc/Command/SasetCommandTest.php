<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\SasetCommand;
use App\NickServ\Adapter\In\Irc\Command\SetEmailHandler;
use App\NickServ\Adapter\In\Irc\Command\SetLanguageHandler;
use App\NickServ\Adapter\In\Irc\Command\SetMsgHandler;
use App\NickServ\Adapter\In\Irc\Command\SetPasswordHandler;
use App\NickServ\Adapter\In\Irc\Command\SetPrivateHandler;
use App\NickServ\Adapter\In\Irc\Command\SetTimezoneHandler;
use App\NickServ\Adapter\In\Irc\Command\SetVhostHandler;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingEmailChangeRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\Service\NickProtectabilityResult;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\Service\VhostValidator;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\AsyncMessageDispatcherInterface;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(SasetCommand::class)]
final class SasetCommandTest extends TestCase
{
    /**
     * @param string[] $args
     */
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        NickServNotifierInterface $notifier,
        TranslationInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            $senderAccount,
            'SASET',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new ServiceNicknameRegistry([$provider]);
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslationInterface::class);

        $targetValidator = $this->createStub(NickTargetValidator::class);
        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(null, null, ['Target', 'PASSWORD', 'newpass'], $notifier, $translator));
    }

    #[Test]
    public function repliesSyntaxErrorOnInsufficientArgs(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createStub(NickTargetValidator::class);
        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['Target'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function repliesUnknownOptionWhenOptionNotSupported(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createStub(NickTargetValidator::class);
        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['Target', 'UNKNOWN', 'value'], $notifier, $translator));

        self::assertSame(['saset.unknown_option'], $messages);
    }

    #[Test]
    public function repliesCannotModifyOperWhenTargetIsRoot(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('RootUser')->willReturn(NickProtectabilityResult::root('RootUser'));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['RootUser', 'PASSWORD', 'newpass'], $notifier, $translator));

        self::assertSame(['saset.cannot_modify_oper'], $messages);
    }

    #[Test]
    public function repliesCannotModifyOperWhenTargetIsIrcop(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('OperTarget')->willReturn(NickProtectabilityResult::ircop('OperTarget'));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['OperTarget', 'LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['saset.cannot_modify_oper'], $messages);
    }

    #[Test]
    public function repliesCannotModifyOperWhenTargetIsService(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('NickServ')->willReturn(NickProtectabilityResult::service('NickServ'));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['NickServ', 'LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['saset.cannot_modify_oper'], $messages);
    }

    #[Test]
    public function repliesNotRegisteredWhenTargetNotRegistered(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('NotRegistered')->willReturn(NickProtectabilityResult::allowed('NotRegistered', null));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['NotRegistered', 'LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['saset.not_registered'], $messages);
    }

    #[Test]
    public function delegatesToPasswordHandlerWhenOptionPassword(): void
    {
        $targetAccount = $this->createMock(RegisteredNick::class);
        $targetAccount->expects(self::once())->method('changePassword')->with('newhash');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);
        $passwordHasher = $this->createStub(PasswordHasher::class);
        $passwordHasher->method('hash')->willReturn('newhash');
        $setPassword = new SetPasswordHandler($nickRepo, $passwordHasher, $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'PASSWORD', 'newpass123'], $notifier, $translator));

        self::assertSame(['set.password.success'], $messages);
    }

    #[Test]
    public function delegatesToLanguageHandlerWhenOptionLanguage(): void
    {
        $targetAccount = $this->createMock(RegisteredNick::class);
        $targetAccount->expects(self::once())->method('changeLanguage')->with('es');
        $targetAccount->method('getLanguage')->willReturn('es');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['set.language.success'], $messages);
    }

    #[Test]
    public function handlesLowercaseOption(): void
    {
        $targetAccount = $this->createMock(RegisteredNick::class);
        $targetAccount->expects(self::once())->method('changeLanguage')->with('en');
        $targetAccount->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'language', 'en'], $notifier, $translator));

        self::assertSame(['set.language.success'], $messages);
    }

    #[Test]
    public function returnsAuditDataWithTargetAndOption(): void
    {
        $targetAccount = $this->createStub(RegisteredNick::class);
        $targetAccount->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $context = $this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'LANGUAGE', 'en'], $notifier, $translator);
        $outcome = $cmd->execute($context);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetUser', $auditData->target);
        self::assertSame('LANGUAGE', $auditData->extra['option']);
        self::assertSame('en', $auditData->extra['value']);
    }

    #[Test]
    public function returnsNullAuditDataForPassword(): void
    {
        $targetAccount = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $passwordHasher = $this->createStub(PasswordHasher::class);
        $passwordHasher->method('hash')->willReturn('hash');
        $setPassword = new SetPasswordHandler($nickRepo, $passwordHasher, $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $context = $this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'PASSWORD', 'newpass'], $notifier, $translator);
        $outcome = $cmd->execute($context);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetUser', $auditData->target);
        self::assertSame('PASSWORD', $auditData->extra['option']);
        self::assertNull($auditData->extra['value']);
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getNickRepositoryReturnsConfiguredRepository(): void
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $cmd = new SasetCommand(
            new SetPasswordHandler($nickRepository, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock()),
            new SetEmailHandler(
                $nickRepository,
                new PendingEmailChangeRegistry(),
                $this->createStub(AsyncMessageDispatcherInterface::class),
                $this->createStub(VerificationTokenGenerator::class),
                $this->createStub(Clock::class),
                $this->createStub(TranslationInterface::class),
                $this->createStub(LoggerInterface::class),
                $this->createStub(EventBusInterface::class)
            ),
            new SetLanguageHandler($nickRepository),
            new SetPrivateHandler($nickRepository),
            new SetMsgHandler($nickRepository),
            new SetTimezoneHandler($nickRepository),
            new SetVhostHandler($nickRepository, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class)),
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
        );

        self::assertSame($nickRepository, $cmd->getNickRepository());
    }

    #[Test]
    public function getRequiredPermissionReturnsSaset(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('nickserv.saset', $cmd->getRequiredPermission());
    }

    #[Test]
    public function getNameReturnsSaset(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('SASET', $cmd->getName());
    }

    #[Test]
    public function getMinArgsReturnsThree(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame(3, $cmd->getMinArgs());
    }

    #[Test]
    public function getSubCommandHelpReturnsAllOptions(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $subCommands = $cmd->getSubCommandHelp();

        self::assertCount(7, $subCommands);
        self::assertSame('PASSWORD', $subCommands[0]['name']);
        self::assertSame('EMAIL', $subCommands[1]['name']);
        self::assertSame('LANGUAGE', $subCommands[2]['name']);
        self::assertSame('TIMEZONE', $subCommands[3]['name']);
        self::assertSame('PRIVATE', $subCommands[4]['name']);
        self::assertSame('MSG', $subCommands[5]['name']);
        self::assertSame('VHOST', $subCommands[6]['name']);
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getSyntaxKeyReturnsSasetSyntax(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('saset.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsSasetHelp(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('saset.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFive(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame(5, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsSasetShort(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('saset.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasher::class), $this->createStub(EventBusInterface::class), $this->clock());
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $this->createStub(AsyncMessageDispatcherInterface::class),
            $this->createStub(VerificationTokenGenerator::class),
            $this->createStub(Clock::class),
            $this->createStub(TranslationInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventBusInterface::class)
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(ForcedVhostCheckerInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame([], $cmd->getHelpParams());
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-06 12:00:00 UTC'));

        return $clock;
    }
}
