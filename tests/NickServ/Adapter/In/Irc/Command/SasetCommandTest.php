<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SasetCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Set\SetNickSetting;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandlerInterface;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\NickServ\Application\UseCase\Set\SetNickSettingOutcome;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SasetCommand::class)]
final class SasetCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new SasetCommand($this->createStub(SetNickSettingHandlerInterface::class));

        self::assertSame('SASET', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('saset.syntax', $command->getSyntaxKey());
        self::assertSame('saset.help', $command->getHelpKey());
        self::assertSame('saset.example', $command->getExampleKey());
        self::assertSame(5, $command->getOrder());
        self::assertSame('saset.short', $command->getShortDescKey());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::SASET, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
        self::assertCount(7, $command->getSubCommandHelp());
        self::assertSame(['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'], array_column($command->getSubCommandHelp(), 'name'));
    }

    #[Test]
    public function rejectsMissingSenderSyntaxAndUnknownOptionWithoutCallingUseCase(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new SasetCommand($handler);

        self::assertFalse($command->execute($this->context(null, []))->success);
        $translations = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['Target', 'PASSWORD'], translationCalls: $translations))->success);
        self::assertContains('saset.password.syntax', $translations);

        $translations = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['Target', 'UNKNOWN'], translationCalls: $translations))->success);
        self::assertContains('saset.syntax', $translations);

        $messages = [];
        $translations = [];
        $translationParameters = [];
        self::assertFalse($command->execute($this->context(
            $this->sender(),
            ['Target', 'UNKNOWN', 'value'],
            $messages,
            $translations,
            $translationParameters,
        ))->success);
        self::assertSame(['saset.unknown_option'], $messages);
        self::assertSame(['saset.unknown_option'], $translations);
        self::assertSame('PASSWORD, EMAIL, LANGUAGE, TIMEZONE, PRIVATE, MSG, VHOST', $translationParameters[0]['%options%']);
    }

    #[Test]
    public function clearsVhostWhenValueIsOmitted(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => 'Target' === $input->targetNickname
                && SetNickSettingOption::Vhost === $input->option
                && '' === $input->value
                && $input->operatorMode,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            SetNickSettingOption::Vhost,
            'Target',
        ));

        $messages = [];
        $outcome = new SasetCommand($handler)->execute($this->context($this->sender(), ['Target', 'VHOST'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['set.vhost.cleared'], $messages);
        self::assertSame(['option' => 'VHOST', 'value' => ''], $outcome->auditData?->extra);
    }

    #[Test]
    public function translatesValidCommandAndReturnsSanitizedAuditData(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => 'Oper' === $input->actor->nickname
                && '2001:db8::1' === $input->actor->ip
                && 'Target' === $input->targetNickname
                && SetNickSettingOption::Password === $input->option
                && 'super secret' === $input->value
                && $input->operatorMode,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            SetNickSettingOption::Password,
            'Target',
        ));

        $outcome = new SasetCommand($handler)->execute($this->context(
            $this->sender('IAENuAAAAAAAAAAAAAAAAQ=='),
            ['Target', 'PASSWORD', 'super', 'secret'],
        ));

        self::assertTrue($outcome->success);
        $auditData = $outcome->auditData;
        if (null === $auditData) {
            self::fail('A successful SASET command must provide audit data.');
        }
        self::assertSame('Target', $auditData->target);
        self::assertSame(['option' => 'PASSWORD', 'value' => null], $auditData->extra);
    }

    #[Test]
    public function returnsRejectedWhenUseCaseResultCannotBePresentedAsSuccess(): void
    {
        $handler = $this->createStub(SetNickSettingHandlerInterface::class);
        $handler->method('handle')->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::TargetNotRegistered,
            SetNickSettingOption::Language,
            'Missing',
        ));

        $messages = [];
        $outcome = new SasetCommand($handler)->execute($this->context(
            $this->sender('not-base64'),
            ['Missing', 'LANGUAGE', 'es'],
            $messages,
        ));

        self::assertFalse($outcome->success);
        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function successfulNonPasswordAuditIncludesValueAndWildcardIpIsAccepted(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => '*' === $input->actor->ip,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            SetNickSettingOption::Language,
            'Target',
            'es',
        ));

        $outcome = new SasetCommand($handler)->execute($this->context(
            $this->sender('*'),
            ['Target', 'language', 'es'],
        ));

        self::assertTrue($outcome->success);
        self::assertSame(['option' => 'LANGUAGE', 'value' => 'es'], $outcome->auditData?->extra);
    }

    #[Test]
    public function emailAuditNeverIncludesAnExtraneousCredential(): void
    {
        $handler = $this->createStub(SetNickSettingHandlerInterface::class);
        $handler->method('handle')->willReturn(new SetNickSettingResult(SetNickSettingOutcome::Changed, SetNickSettingOption::Email, 'Target', 'new@example.com'));
        $outcome = new SasetCommand($handler)->execute($this->context($this->sender(), ['Target', 'EMAIL', 'new@example.com', 'never-audit-token']));
        self::assertTrue($outcome->success);
        self::assertSame(['option' => 'EMAIL', 'value' => 'new@example.com'], $outcome->auditData?->extra);
        self::assertStringNotContainsString('never-audit-token', serialize($outcome->auditData));
    }

    #[Test]
    public function targetRootProtectionTakesPrecedenceOverAnInvalidBusinessMode(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => 'Root' === $input->targetNickname
                && SetNickSettingOption::PrivateMode === $input->option
                && 'MAYBE' === $input->value,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::TargetIsRoot,
            SetNickSettingOption::PrivateMode,
            'Root',
        ));

        $messages = [];
        $outcome = new SasetCommand($handler)->execute($this->context(
            $this->sender(),
            ['Root', 'PRIVATE', 'MAYBE'],
            $messages,
        ));

        self::assertFalse($outcome->success);
        self::assertSame(['saset.cannot_modify_oper'], $messages);
    }

    /**
     * @param list<string>               $args
     * @param list<string>               $messages
     * @param list<string>               $translationCalls
     * @param list<array<string, mixed>> $translationParameters
     */
    private function context(
        ?SenderView $sender,
        array $args,
        array &$messages = [],
        array &$translationCalls = [],
        array &$translationParameters = [],
    ): NickServContext {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []) use (&$translationCalls, &$translationParameters): string {
            $translationCalls[] = $id;
            $translationParameters[] = $parameters;

            return $id;
        });
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new NickServContext(
            $sender,
            null,
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
            new ServiceNicknameRegistry([$provider]),
        );
    }

    private function sender(string $ipBase64 = '*'): SenderView
    {
        return new SenderView('OPER1', 'Oper', 'ident', 'oper.test', 'cloak.test', $ipBase64, true, true, 'SID1');
    }
}
