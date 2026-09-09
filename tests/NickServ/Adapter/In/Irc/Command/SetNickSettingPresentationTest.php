<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SetNickSettingPresentation;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\NickServ\Application\UseCase\Set\SetNickSettingOutcome;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetNickSettingPresentation::class)]
final class SetNickSettingPresentationTest extends TestCase
{
    /** @return iterable<string, array{SetNickSettingOption, ?string, string}> */
    public static function changedOptions(): iterable
    {
        yield 'password' => [SetNickSettingOption::Password, null, 'set.password.success'];
        yield 'email' => [SetNickSettingOption::Email, 'new@example.com', 'set.email.success'];
        yield 'language' => [SetNickSettingOption::Language, 'es', 'set.language.success'];
        yield 'timezone set' => [SetNickSettingOption::Timezone, 'Europe/Madrid', 'set.timezone.success'];
        yield 'timezone cleared' => [SetNickSettingOption::Timezone, null, 'set.timezone.cleared'];
        yield 'private on' => [SetNickSettingOption::PrivateMode, 'ON', 'set.private.on'];
        yield 'private off' => [SetNickSettingOption::PrivateMode, 'OFF', 'set.private.off'];
        yield 'message on' => [SetNickSettingOption::MessageMode, 'ON', 'set.msg.on'];
        yield 'message off' => [SetNickSettingOption::MessageMode, 'OFF', 'set.msg.off'];
        yield 'vhost set' => [SetNickSettingOption::Vhost, 'alice.users', 'set.vhost.success'];
        yield 'vhost cleared' => [SetNickSettingOption::Vhost, null, 'set.vhost.cleared'];
    }

    #[Test]
    #[DataProvider('changedOptions')]
    public function presentsSuccessfulChanges(SetNickSettingOption $option, ?string $value, string $expectedKey): void
    {
        $messages = [];
        $context = $this->context($messages);

        self::assertTrue(SetNickSettingPresentation::present($context, new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            $option,
            'Alice',
            $value,
        )));
        self::assertSame([$expectedKey], $messages);
    }

    /** @return iterable<string, array{SetNickSettingOutcome, SetNickSettingOption, ?string, string, bool}> */
    public static function otherOutcomes(): iterable
    {
        yield 'session language' => [SetNickSettingOutcome::SessionLanguageChanged, SetNickSettingOption::Language, 'es', 'set.language.success', true];
        yield 'confirmation sent' => [SetNickSettingOutcome::EmailConfirmationSent, SetNickSettingOption::Email, null, 'set.email.pending_sent', true];
        yield 'missing account' => [SetNickSettingOutcome::TargetNotRegistered, SetNickSettingOption::Password, null, 'error.not_identified', false];
        yield 'no current email' => [SetNickSettingOutcome::NoCurrentEmail, SetNickSettingOption::Email, null, 'error.not_identified', false];
        yield 'root target' => [SetNickSettingOutcome::TargetIsRoot, SetNickSettingOption::Language, null, 'saset.cannot_modify_oper', false];
        yield 'ircop target' => [SetNickSettingOutcome::TargetIsIrcop, SetNickSettingOption::Language, null, 'saset.cannot_modify_oper', false];
        yield 'service target' => [SetNickSettingOutcome::TargetIsService, SetNickSettingOption::Language, null, 'saset.cannot_modify_oper', false];
        yield 'missing value' => [SetNickSettingOutcome::MissingValue, SetNickSettingOption::Email, null, 'error.syntax', false];
        yield 'invalid flag' => [SetNickSettingOutcome::InvalidFlag, SetNickSettingOption::PrivateMode, null, 'error.syntax', false];
        yield 'invalid email' => [SetNickSettingOutcome::InvalidEmail, SetNickSettingOption::Email, 'bad', 'register.invalid_email', false];
        yield 'email used' => [SetNickSettingOutcome::EmailAlreadyUsed, SetNickSettingOption::Email, 'used@example.com', 'register.email_already_used', false];
        yield 'email token' => [SetNickSettingOutcome::InvalidEmailToken, SetNickSettingOption::Email, null, 'set.email.invalid_token', false];
        yield 'mail failure' => [SetNickSettingOutcome::MailDeliveryFailed, SetNickSettingOption::Email, null, 'error.mail_failed', false];
        yield 'language' => [SetNickSettingOutcome::InvalidLanguage, SetNickSettingOption::Language, 'xx', 'set.language.invalid', false];
        yield 'timezone' => [SetNickSettingOutcome::InvalidTimezone, SetNickSettingOption::Timezone, 'Mars/Olympus', 'set.timezone.invalid', false];
        yield 'forced vhost' => [SetNickSettingOutcome::ForcedVhost, SetNickSettingOption::Vhost, null, 'set.vhost.forced', false];
        yield 'invalid vhost' => [SetNickSettingOutcome::InvalidVhost, SetNickSettingOption::Vhost, null, 'set.vhost.invalid', false];
        yield 'taken vhost' => [SetNickSettingOutcome::VhostTaken, SetNickSettingOption::Vhost, null, 'set.vhost.taken', false];
    }

    #[Test]
    #[DataProvider('otherOutcomes')]
    public function presentsEverySemanticOutcome(
        SetNickSettingOutcome $outcome,
        SetNickSettingOption $option,
        ?string $value,
        string $expectedKey,
        bool $accepted,
    ): void {
        $messages = [];
        $context = $this->context($messages);

        self::assertSame($accepted, SetNickSettingPresentation::present($context, new SetNickSettingResult(
            $outcome,
            $option,
            'Alice',
            $value,
            'old@example.com',
            'new@example.com',
        )));
        self::assertSame([$expectedKey], $messages);
    }

    /** @param list<string> $messages */
    private function context(array &$messages): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new NickServContext(
            new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', '*'),
            null,
            'SET',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([$provider]),
        );
    }
}
