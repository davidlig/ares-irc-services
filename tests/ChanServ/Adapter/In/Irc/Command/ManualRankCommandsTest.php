<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\AdminCommand;
use App\ChanServ\Adapter\In\Irc\Command\DeadminCommand;
use App\ChanServ\Adapter\In\Irc\Command\DehalfopCommand;
use App\ChanServ\Adapter\In\Irc\Command\DeopCommand;
use App\ChanServ\Adapter\In\Irc\Command\DevoiceCommand;
use App\ChanServ\Adapter\In\Irc\Command\HalfopCommand;
use App\ChanServ\Adapter\In\Irc\Command\OpCommand;
use App\ChanServ\Adapter\In\Irc\Command\VoiceCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRank;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankHandlerInterface;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankOutcome;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankResult;
use App\ChanServ\Application\UseCase\ManageManualRank\ManualRankOperation;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

#[CoversClass(AdminCommand::class)]
#[CoversClass(DeadminCommand::class)]
#[CoversClass(HalfopCommand::class)]
#[CoversClass(DehalfopCommand::class)]
#[CoversClass(OpCommand::class)]
#[CoversClass(DeopCommand::class)]
#[CoversClass(VoiceCommand::class)]
#[CoversClass(DevoiceCommand::class)]
final class ManualRankCommandsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<ChanServCommandInterface>, string, string, string, int}>
     */
    public static function commandProvider(): iterable
    {
        yield 'admin' => [AdminCommand::class, 'ADMIN', 'admin', 'admin', 18];
        yield 'deadmin' => [DeadminCommand::class, 'DEADMIN', 'deadmin', 'deadmin', 19];
        yield 'halfop' => [HalfopCommand::class, 'HALFOP', 'halfop', 'halfop', 22];
        yield 'dehalfop' => [DehalfopCommand::class, 'DEHALFOP', 'dehalfop', 'dehalfop', 23];
        yield 'op' => [OpCommand::class, 'OP', 'op', 'op', 20];
        yield 'deop' => [DeopCommand::class, 'DEOP', 'deop', 'deop', 21];
        yield 'voice' => [VoiceCommand::class, 'VOICE', 'voice', 'voice', 24];
        yield 'devoice' => [DevoiceCommand::class, 'DEVOICE', 'devoice', 'devoice', 25];
    }

    /** @param class-string<ChanServCommandInterface> $class */
    #[Test]
    #[DataProvider('commandProvider')]
    public function exposesCommandMetadata(string $class, string $name, string $syntaxBase, string $helpBase, int $order): void
    {
        $command = new $class($this->createStub(ManageManualRankHandlerInterface::class));

        self::assertSame($name, $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame($syntaxBase . '.syntax', $command->getSyntaxKey());
        self::assertSame($helpBase . '.help', $command->getHelpKey());
        self::assertSame($order, $command->getOrder());
        self::assertSame($helpBase . '.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
        self::assertFalse($command->allowsSuspendedChannel());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertTrue($command->usesLevelFounder());
    }

    #[Test]
    public function ignoresInputWithoutSender(): void
    {
        $handler = $this->createMock(ManageManualRankHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        new AdminCommand($handler)->execute($this->context(null, null, []));
    }

    #[Test]
    public function rejectsInvalidChannelAndMissingTargetBeforeCallingApplication(): void
    {
        $handler = $this->createMock(ManageManualRankHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];
        $context = $this->context($this->sender(), $this->account(), ['invalid', 'Nick'], $messages);

        new AdminCommand($handler)->execute($context);
        self::assertSame(['error.invalid_channel'], $messages);

        $messages = [];
        new AdminCommand($handler)->execute($this->context($this->sender(), $this->account(), ['#channel'], $messages));
        self::assertSame(['error.syntax'], $messages);
    }

    /** @return iterable<string, array{ManageManualRankOutcome, string, ManualRankOperation, ?int}> */
    public static function rejectedOutcomeProvider(): iterable
    {
        yield 'not identified' => [ManageManualRankOutcome::NotIdentified, 'error.not_identified', ManualRankOperation::Admin, null];
        yield 'rank unsupported' => [ManageManualRankOutcome::RankNotSupported, 'admin.not_supported', ManualRankOperation::Admin, null];
        yield 'nick unregistered' => [ManageManualRankOutcome::TargetNickNotRegistered, 'error.nick_not_registered', ManualRankOperation::Admin, null];
        yield 'not on channel' => [ManageManualRankOutcome::TargetNotOnChannel, 'admin.user_not_on_channel', ManualRankOperation::Admin, null];
        yield 'secure level' => [ManageManualRankOutcome::SecureLevelRequired, 'secure.requires_min_level:+a', ManualRankOperation::Admin, 400];
        yield 'access too high' => [ManageManualRankOutcome::TargetAccessTooHigh, 'error.insufficient_access', ManualRankOperation::Deadmin, null];
    }

    /** @return iterable<string, array{class-string<ChanServCommandInterface>, string, string}> */
    public static function appliedPresentationProvider(): iterable
    {
        yield 'admin' => [AdminCommand::class, 'admin.notice_grant', '+a'];
        yield 'deadmin' => [DeadminCommand::class, 'admin.notice_grant', '-a'];
        yield 'halfop' => [HalfopCommand::class, 'halfop.notice_grant', '+h'];
        yield 'dehalfop' => [DehalfopCommand::class, 'halfop.notice_grant', '-h'];
        yield 'op' => [OpCommand::class, 'op.notice_grant', '+o'];
        yield 'deop' => [DeopCommand::class, 'op.notice_grant', '-o'];
        yield 'voice' => [VoiceCommand::class, 'voice.notice_grant', '+v'];
        yield 'devoice' => [DevoiceCommand::class, 'voice.notice_grant', '-v'];
    }

    #[Test]
    #[DataProvider('rejectedOutcomeProvider')]
    public function presentsSemanticRejection(ManageManualRankOutcome $outcome, string $message, ManualRankOperation $operation, ?int $requiredLevel): void
    {
        $handler = $this->createStub(ManageManualRankHandlerInterface::class);
        $handler->method('handle')->willReturn(ManageManualRankResult::of($outcome, '#channel', 'Target', $requiredLevel));
        $messages = [];
        $class = ManualRankOperation::Deadmin === $operation ? DeadminCommand::class : AdminCommand::class;

        new $class($handler)->execute($this->context($this->sender(), $this->account(), ['#channel', 'Target'], $messages));

        self::assertSame($message, $messages[0]);
    }

    #[Test]
    public function sendsAppliedGrantAndRevokePresentationsAndTypedInput(): void
    {
        $captured = null;
        $handler = $this->createStub(ManageManualRankHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(static function (ManageManualRank $command) use (&$captured): ManageManualRankResult {
            $captured = $command;

            return ManageManualRankResult::of(ManageManualRankOutcome::Applied, $command->channelName, $command->targetNickname);
        });
        $messages = [];
        $notices = [];

        new VoiceCommand($handler)->execute($this->context(
            $this->sender(),
            $this->account(),
            ['#Channel', 'Target'],
            $messages,
            $notices,
            true,
        ));

        self::assertInstanceOf(ManageManualRank::class, $captured);
        self::assertSame('#Channel', $captured->channelName);
        self::assertSame('Target', $captured->targetNickname);
        self::assertSame(7, $captured->actorAccountId);
        self::assertTrue($captured->founderOverride);
        self::assertSame(ManualRankOperation::Voice, $captured->operation);
        self::assertSame(['voice.notice_grant:+v', 'voice.done'], [$notices[0], $messages[0]]);

        $messages = [];
        $notices = [];
        new DevoiceCommand($handler)->execute($this->context($this->sender(), $this->account(), ['#Channel', 'Target'], $messages, $notices));
        self::assertSame(['voice.notice_grant:-v', 'devoice.done'], [$notices[0], $messages[0]]);
    }

    /** @param class-string<ChanServCommandInterface> $class */
    #[Test]
    #[DataProvider('appliedPresentationProvider')]
    public function presentsEverySupportedManualRankOperation(string $class, string $noticeKey, string $displayMode): void
    {
        $handler = $this->createStub(ManageManualRankHandlerInterface::class);
        $handler->method('handle')->willReturn(ManageManualRankResult::of(ManageManualRankOutcome::Applied, '#channel', 'Target'));
        $messages = [];
        $notices = [];

        new $class($handler)->execute($this->context(
            $this->sender(),
            $this->account(),
            ['#channel', 'Target'],
            $messages,
            $notices,
        ));

        self::assertSame($noticeKey . ':' . $displayMode, $notices[0]);
    }

    private function sender(): SenderView
    {
        return new SenderView('UID1', 'Actor', 'ident', 'host', 'cloak', '*');
    }

    private function account(): ChanAccountView
    {
        return new ChanAccountView(7, 'Actor', 'en');
    }

    /**
     * @param list<string> $args
     * @param list<string> $messages
     * @param list<string> $notices
     */
    private function context(
        ?SenderView $sender,
        ?ChanAccountView $account,
        array $args,
        array &$messages = [],
        array &$notices = [],
        bool $founder = false,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $channel, string $message) use (&$notices): void {
            $notices[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params = []): string {
            $mode = $params['%mode%'] ?? null;

            return $key . (is_string($mode) ? ':' . $mode : '');
        });
        $serviceNick = $this->createStub(ServiceNicknameProviderInterface::class);
        $serviceNick->method('getServiceKey')->willReturn('chanserv');
        $serviceNick->method('getNickname')->willReturn('ChanServ');
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('hasAdmin')->willReturn(true);
        $modeSupport->method('hasHalfop')->willReturn(true);

        $context = new ChanServContext(
            $sender,
            $account,
            'COMMAND',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $modeSupport,
            $this->createStub(NetworkUserLookupPort::class),
            new ServiceNicknameRegistry([$serviceNick]),
            $founder,
        );

        return $context;
    }
}
