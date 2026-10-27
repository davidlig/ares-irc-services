<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SetCommand;
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
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;

#[CoversClass(SetCommand::class)]
final class SetCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new SetCommand($this->createStub(SetNickSettingHandlerInterface::class));

        self::assertSame('SET', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('set.syntax', $command->getSyntaxKey());
        self::assertSame('set.help', $command->getHelpKey());
        self::assertSame('set.example', $command->getExampleKey());
        self::assertSame(4, $command->getOrder());
        self::assertSame('set.short', $command->getShortDescKey());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::IDENTIFIED_OWNER, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
        self::assertCount(7, $command->getSubCommandHelp());
        self::assertSame(['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'], array_column($command->getSubCommandHelp(), 'name'));
    }

    #[Test]
    public function rejectsMissingSenderSyntaxAndUnknownOptionWithoutCallingUseCase(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new SetCommand($handler);

        self::assertFalse($command->execute($this->context(null, []))->success);
        $translations = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['PASSWORD'], translationCalls: $translations))->success);
        self::assertContains('set.password.syntax', $translations);

        $translations = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['UNKNOWN'], translationCalls: $translations))->success);
        self::assertContains('set.syntax', $translations);

        $messages = [];
        $translations = [];
        $translationParameters = [];
        self::assertFalse($command->execute($this->context(
            $this->sender(),
            ['UNKNOWN', 'value'],
            $messages,
            translationCalls: $translations,
            translationParameters: $translationParameters,
        ))->success);
        self::assertSame(['set.unknown_option'], $messages);
        self::assertSame(['set.unknown_option'], $translations);
        self::assertSame('PASSWORD, EMAIL, LANGUAGE, TIMEZONE, PRIVATE, MSG, VHOST', $translationParameters[0]['%options%']);
    }

    #[Test]
    public function clearsVhostWhenValueIsOmitted(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => SetNickSettingOption::Vhost === $input->option
                && '' === $input->value
                && !$input->operatorMode,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            SetNickSettingOption::Vhost,
            'Alice',
        ));

        $messages = [];
        $outcome = new SetCommand($handler)->execute($this->context($this->sender(), ['VHOST'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['set.vhost.cleared'], $messages);
    }

    #[Test]
    public function translatesValidCommandIntoTypedInputAndReturnsSuccess(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => 'Alice' === $input->actor->nickname
                && 17 === $input->actor->accountId
                && 'UID1' === $input->actor->uid
                && 'SID1' === $input->actor->serverSid
                && 'ident@host.test' === $input->actor->host
                && '127.0.0.1' === $input->actor->ip
                && 'Alice' === $input->targetNickname
                && SetNickSettingOption::Email === $input->option
                && 'new@example.com TOKEN' === $input->value
                && !$input->operatorMode
                && 'en' === $input->locale
                && 'ip:fwAAAQ==' === $input->clientKey,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            SetNickSettingOption::Email,
            'Alice',
            'new@example.com',
        ));

        $messages = [];
        $outcome = new SetCommand($handler)->execute($this->context(
            $this->sender('fwAAAQ=='),
            ['EMAIL', 'new@example.com', 'TOKEN'],
            $messages,
            $this->account(17),
        ));

        self::assertTrue($outcome->success);
        self::assertSame(['set.email.success'], $messages);
    }

    #[Test]
    public function returnsRejectedWhenPresentationRejectsAndPreservesInvalidIpText(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => 'not-base64' === $input->actor->ip,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::InvalidFlag,
            SetNickSettingOption::MessageMode,
            'Alice',
        ));

        $messages = [];
        $outcome = new SetCommand($handler)->execute($this->context(
            $this->sender('not-base64'),
            ['MSG', 'MAYBE'],
            $messages,
        ));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function leavesInvalidPrivateModeToBusinessValidation(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => SetNickSettingOption::PrivateMode === $input->option
                && 'MAYBE' === $input->value,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::InvalidFlag,
            SetNickSettingOption::PrivateMode,
            'Alice',
        ));

        $messages = [];
        $outcome = new SetCommand($handler)->execute($this->context($this->sender(), ['PRIVATE', 'MAYBE'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function leavesEmailTokenRequirementToTheUseCase(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => SetNickSettingOption::Email === $input->option
                && 'new@example.com' === $input->value,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::TokenRequired,
            SetNickSettingOption::Email,
            'Alice',
        ));

        $messages = [];
        $outcome = new SetCommand($handler)->execute($this->context(
            $this->sender(),
            ['EMAIL', 'new@example.com'],
            $messages,
        ));

        self::assertFalse($outcome->success);
        self::assertSame(['set.email.token_required'], $messages);
    }

    #[Test]
    public function normalizesMissingEncodedIpToWildcard(): void
    {
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNickSetting $input): bool => '*' === $input->actor->ip,
        ))->willReturn(new SetNickSettingResult(
            SetNickSettingOutcome::Changed,
            SetNickSettingOption::Password,
            'Alice',
        ));

        self::assertTrue(new SetCommand($handler)->execute($this->context(
            $this->sender(''),
            ['PASSWORD', 'new-secret'],
        ))->success);
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
        ?RegisteredNick $account = null,
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
            $account,
            'SET',
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
        return new SenderView('UID1', 'Alice', 'ident', 'host.test', 'cloak.test', $ipBase64, true, false, 'SID1');
    }

    private function account(int $id): RegisteredNick
    {
        $account = RegisteredNick::createPending(
            'Alice',
            'hash',
            'alice@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );
        $account->activate();
        new ReflectionClass($account)->getProperty('id')->setValue($account, $id);

        return $account;
    }
}
