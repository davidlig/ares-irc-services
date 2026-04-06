<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\SasetCommand;
use App\Application\NickServ\Command\Handler\SetEmailHandler;
use App\Application\NickServ\Command\Handler\SetLanguageHandler;
use App\Application\NickServ\Command\Handler\SetMsgHandler;
use App\Application\NickServ\Command\Handler\SetPasswordHandler;
use App\Application\NickServ\Command\Handler\SetPrivateHandler;
use App\Application\NickServ\Command\Handler\SetTimezoneHandler;
use App\Application\NickServ\Command\Handler\SetVhostHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SasetCommand::class)]
final class SasetCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);

        $targetValidator = $this->createStub(NickTargetValidator::class);
        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $cmd->execute($this->createContext(null, null, ['Target', 'PASSWORD', 'newpass'], $notifier, $translator));
    }

    #[Test]
    public function repliesSyntaxErrorOnInsufficientArgs(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $targetAccount->expects(self::once())->method('changePasswordWithHasher')->with('newpass123', self::isInstanceOf(PasswordHasherInterface::class));
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $passwordHasher->method('hash')->willReturn('newhash');
        $setPassword = new SetPasswordHandler($nickRepo, $passwordHasher, $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class, $this->createStub(EventDispatcherInterface::class)), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $context = $this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'LANGUAGE', 'en'], $notifier, $translator);
        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
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
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $passwordHasher->method('hash')->willReturn('hash');
        $setPassword = new SetPasswordHandler($nickRepo, $passwordHasher, $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class, $this->createStub(EventDispatcherInterface::class)), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $targetValidator = $this->createMock(NickTargetValidator::class);
        $targetValidator->expects(self::once())->method('validate')->with('TargetUser')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        $context = $this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), null, ['TargetUser', 'PASSWORD', 'newpass'], $notifier, $translator);
        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetUser', $auditData->target);
        self::assertSame('PASSWORD', $auditData->extra['option']);
        self::assertNull($auditData->extra['value']);
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsSaset(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('nickserv.saset', $cmd->getRequiredPermission());
    }

    #[Test]
    public function getNameReturnsSaset(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('SASET', $cmd->getName());
    }

    #[Test]
    public function getMinArgsReturnsThree(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame(3, $cmd->getMinArgs());
    }

    #[Test]
    public function getSubCommandHelpReturnsAllOptions(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
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
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getSyntaxKeyReturnsSasetSyntax(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('saset.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsSasetHelp(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('saset.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFive(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame(5, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsSasetShort(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame('saset.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class), $this->createStub(EventDispatcherInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(ForbiddenVhostRepositoryInterface::class));
        $targetValidator = $this->createStub(NickTargetValidator::class);

        $cmd = new SasetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $targetValidator);
        self::assertSame([], $cmd->getHelpParams());
    }
}
