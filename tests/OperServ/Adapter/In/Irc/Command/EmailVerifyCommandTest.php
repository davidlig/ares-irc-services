<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\EmailVerifyCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\UseCase\ManageEmailVerification\EmailVerificationAction;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerification;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationHandlerInterface;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationOutcome;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(EmailVerifyCommand::class)]
#[CoversClass(ManageEmailVerification::class)]
#[CoversClass(ManageEmailVerificationResult::class)]
#[CoversClass(EmailVerificationAction::class)]
final class EmailVerifyCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new EmailVerifyCommand(new RecordingEmailVerifyCommandHandler());

        self::assertSame('EMAILVERIFY', $command->getName());
        self::assertSame(['VREG'], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('emailverify.syntax', $command->getSyntaxKey());
        self::assertSame('emailverify.help', $command->getHelpKey());
        self::assertSame(45, $command->getOrder());
        self::assertSame('emailverify.short', $command->getShortDescKey());
        self::assertSame(['ON', 'OFF', 'STATUS'], array_column($command->getSubCommandHelp(), 'name'));
        self::assertTrue($command->isOperOnly());
        self::assertSame(OperatorAuthorizationAttribute::ROOT, $command->getRequiredPermission());
    }

    #[Test]
    public function getExampleKeyReturnsCorrectKey(): void
    {
        $command = new EmailVerifyCommand(new RecordingEmailVerifyCommandHandler());

        self::assertSame('emailverify.example', $command->getExampleKey());
    }

    #[Test]
    public function mapsSubcommandsToActions(): void
    {
        $handler = new RecordingEmailVerifyCommandHandler();
        $command = new EmailVerifyCommand($handler);

        $command->execute($this->context(['on']));
        $command->execute($this->context(['OFF']));
        $command->execute($this->context(['Status']));
        $command->execute($this->context(['nonsense']));
        $command->execute($this->context([]));

        self::assertSame(
            [
                EmailVerificationAction::Enable,
                EmailVerificationAction::Disable,
                EmailVerificationAction::Status,
                EmailVerificationAction::Unknown,
                EmailVerificationAction::Unknown,
            ],
            array_map(static fn (ManageEmailVerification $input): EmailVerificationAction => $input->action, $handler->inputs),
        );
        self::assertSame('Oper', $handler->inputs[0]->actor);
        self::assertSame(42, $handler->inputs[0]->actorAccountId);
    }

    #[Test]
    #[DataProvider('presentations')]
    public function presentsSemanticOutcomes(ManageEmailVerificationResult $result, string $expectedKey): void
    {
        $notifier = new RecordingEmailVerifyNotifier();

        new EmailVerifyCommand(new RecordingEmailVerifyCommandHandler($result))
            ->execute($this->context(['STATUS'], $notifier));

        self::assertSame($expectedKey, $notifier->messages[0]);
    }

    /** @return iterable<string, array{ManageEmailVerificationResult, string}> */
    public static function presentations(): iterable
    {
        yield 'enabled' => [ManageEmailVerificationResult::enabled(), 'emailverify.enabled'];
        yield 'disabled' => [ManageEmailVerificationResult::disabled(), 'emailverify.disabled'];
        yield 'already enabled' => [ManageEmailVerificationResult::alreadyEnabled(), 'emailverify.already_enabled'];
        yield 'already disabled' => [ManageEmailVerificationResult::alreadyDisabled(), 'emailverify.already_disabled'];
        yield 'status on' => [ManageEmailVerificationResult::status(true), 'emailverify.status_on'];
        yield 'status off' => [ManageEmailVerificationResult::status(false), 'emailverify.status_off'];
        yield 'unknown' => [ManageEmailVerificationResult::unknownAction(), 'emailverify.unknown_sub'];
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = new RecordingEmailVerifyCommandHandler();
        $notifier = new RecordingEmailVerifyNotifier();

        new EmailVerifyCommand($handler)->execute($this->context(['ON'], $notifier, true));

        self::assertSame([], $handler->inputs);
        self::assertSame([], $notifier->messages);
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, ?RecordingEmailVerifyNotifier $notifier = null, bool $withoutSender = false): OperServContext
    {
        $notifier ??= new RecordingEmailVerifyNotifier();

        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            $withoutSender ? null : new NickAccountData(42, 'Oper', 'en'),
            'EMAILVERIFY',
            $arguments,
            $notifier,
            $notifier->translations,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );
    }
}

final class RecordingEmailVerifyCommandHandler implements ManageEmailVerificationHandlerInterface
{
    /** @var list<ManageEmailVerification> */
    public array $inputs = [];

    public function __construct(
        private readonly ManageEmailVerificationResult $result = new ManageEmailVerificationResult(ManageEmailVerificationOutcome::UnknownAction),
    ) {}

    public function handle(ManageEmailVerification $command): ManageEmailVerificationResult
    {
        $this->inputs[] = $command;

        return $this->result;
    }
}

final class RecordingEmailVerifyNotifier implements OperServNotifierInterface
{
    /** @var list<string> */
    public array $messages = [];

    public RecordingEmailVerifyTranslation $translations;

    public function __construct()
    {
        $this->translations = new RecordingEmailVerifyTranslation();
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = $message;
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->messages[] = $message;
    }

    public function getNick(): string
    {
        return 'OperServ';
    }

    public function getUid(): string
    {
        return '001OS';
    }

    public function getServiceKey(): string
    {
        return 'operserv';
    }
}

final class RecordingEmailVerifyTranslation implements TranslatorInterface
{
    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
