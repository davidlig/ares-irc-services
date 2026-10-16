<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SaregisterCommand::class)]
#[CoversClass(RegisterChannel::class)]
#[CoversClass(RegisterChannelResult::class)]
final class SaregisterCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new SaregisterCommand(new RecordingRegisterChannelHandler());

        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.help', $command->getHelpKey());
        self::assertSame(81, $command->getOrder());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame(ChanServPermission::SAREGISTER, $command->getRequiredPermission());
        self::assertFalse($command->allowsSuspendedChannel());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function forcesRegistrationForAChannelTheOperatorIsNotIn(): void
    {
        $handler = new RecordingRegisterChannelHandler();
        $messages = [];

        new SaregisterCommand($handler)->execute($this->context($messages, withSender: true));

        self::assertNotNull($handler->last);
        self::assertTrue($handler->last->force);
        self::assertTrue($handler->last->hasRequiredChannelRank);
        self::assertFalse($handler->last->channelExistsOnNetwork);
        self::assertSame('#SitioChat', $handler->last->channelName);
        self::assertSame('descripción', $handler->last->description);
        self::assertSame(1, $handler->last->accountId);
        self::assertSame('Oper', $handler->last->actorNickname);
        self::assertTrue($handler->last->actorIrcOperator);
    }

    #[Test]
    #[DataProvider('presentations')]
    public function presentsSemanticOutcomes(RegisterChannelResult $result, string $expectedKey): void
    {
        $messages = [];

        new SaregisterCommand(new RecordingRegisterChannelHandler($result))
            ->execute($this->context($messages, withSender: true));

        self::assertSame([$expectedKey], $messages);
    }

    /** @return iterable<string, array{RegisterChannelResult, string}> */
    public static function presentations(): iterable
    {
        yield 'registered' => [RegisterChannelResult::registered(), 'saregister.done'];
        yield 'not identified' => [RegisterChannelResult::notIdentified(), 'error.not_identified'];
        yield 'pending deletion' => [RegisterChannelResult::pendingDeletion(), 'register.pending_deletion'];
        yield 'not on network' => [RegisterChannelResult::channelNotOnNetwork(), 'register.channel_not_on_network'];
        yield 'insufficient rank' => [RegisterChannelResult::insufficientChannelRank(), 'register.insufficient_channel_rank'];
        yield 'throttled' => [RegisterChannelResult::throttled(7), 'register.throttled'];
        yield 'limit exceeded' => [RegisterChannelResult::founderLimitExceeded(3), 'register.limit_exceeded'];
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(RegisterChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        new SaregisterCommand($handler)->execute($this->context($messages, withSender: false));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function rejectsAnInvalidChannelNameBeforeConsultingTheHandler(): void
    {
        $handler = $this->createMock(RegisterChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        new SaregisterCommand($handler)->execute(
            $this->context($messages, withSender: true, args: ['SitioChat', 'descripción']),
        );

        self::assertSame(['error.invalid_channel'], $messages);
    }

    /**
     * @param list<string>  $messages
     * @param array<string> $args
     */
    private function context(array &$messages, bool $withSender, array $args = ['#SitioChat', 'descripción']): ChanServContext
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key) use (&$messages): string {
            $messages[] = $key;

            return $key;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);

        return new ChanServContext(
            $withSender ? new SenderView('UID1', 'Oper', 'i', 'h', 'c', 'ip', true, true) : null,
            $withSender ? new ChanAccountView(1, 'Oper', 'en') : null,
            'SAREGISTER',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            new ServiceNicknameRegistry([$this->nicknameProvider()]),
        );
    }

    private function nicknameProvider(): ServiceNicknameProviderInterface
    {
        return new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'chanserv';
            }

            public function getNickname(): string
            {
                return 'ChanServ';
            }
        };
    }
}

final class RecordingRegisterChannelHandler implements RegisterChannelHandlerInterface
{
    public ?RegisterChannel $last = null;

    private RegisterChannelResult $result;

    public function __construct(?RegisterChannelResult $result = null)
    {
        $this->result = $result ?? RegisterChannelResult::registered();
    }

    public function handle(RegisterChannel $command): RegisterChannelResult
    {
        $this->last = $command;

        return $this->result;
    }
}
