<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannel;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\SaregisterChannel\SaregisterChannelResult;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SaregisterCommand::class)]
#[UsesClass(ChanServContext::class)]
#[UsesClass(ChanServCommandRegistry::class)]
#[UsesClass(ServiceNicknameRegistry::class)]
#[UsesClass(NullChannelModeSupport::class)]
#[UsesClass(SaregisterChannelResult::class)]
final class SaregisterCommandTest extends TestCase
{
    #[Test]
    public function metadataRequiresIrcopPermission(): void
    {
        $command = new SaregisterCommand($this->createStub(SaregisterChannelHandlerInterface::class));
        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(3, $command->getMinArgs());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.help', $command->getHelpKey());
        self::assertSame('saregister.example', $command->getExampleKey());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame(72, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertSame([], $command->getHelpParams());
        self::assertTrue($command->isOperOnly());
        self::assertSame(ChanServPermission::SAREGISTER, $command->getRequiredPermission());
        self::assertFalse($command->allowsSuspendedChannel());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function rejectedInputDoesNotCallUseCase(): void
    {
        $handler = $this->createMock(SaregisterChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new SaregisterCommand($handler);
        $messages = [];

        self::assertFalse($command->execute($this->context(null, ['#ops', 'Founder', 'Description'], $messages))->success);
        self::assertFalse($command->execute($this->context($this->sender(), ['badchannel', 'Founder', 'Description'], $messages))->success);
        self::assertFalse($command->execute($this->context($this->sender(), ['#ops'], $messages))->success);
        self::assertFalse($command->execute($this->context($this->sender(), ['#ops', 'Founder'], $messages))->success);
    }

    #[Test]
    public function handlesSuccessfulRegistration(): void
    {
        $handler = $this->createMock(SaregisterChannelHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(function (SaregisterChannel $cmd): bool {
            return '#mychan' === $cmd->channelName
                && 'Alice' === $cmd->targetNickname
                && 'Official channel' === $cmd->description
                && 'Oper' === $cmd->actorNickname
                && true === $cmd->actorIrcOperator;
        }))->willReturn(SaregisterChannelResult::registered('#mychan', 'Alice'));

        $command = new SaregisterCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->context($this->sender(), ['#mychan', 'Alice', 'Official channel'], $messages));

        self::assertTrue($outcome->success);
        self::assertNotNull($outcome->auditData);
        self::assertSame('#mychan', $outcome->auditData->target);
        self::assertSame('Alice', $outcome->auditData->extra['founder']);
    }

    #[Test]
    public function handlesAllErrorOutcomes(): void
    {
        $cases = [
            SaregisterChannelResult::invalidChannelName('#bad'),
            SaregisterChannelResult::alreadyRegistered('#taken'),
            SaregisterChannelResult::pendingDeletion('#pending'),
            SaregisterChannelResult::targetNotFound('Ghost'),
            SaregisterChannelResult::targetNotRegistered('Unregistered'),
            SaregisterChannelResult::targetSuspended('Suspended'),
        ];

        foreach ($cases as $result) {
            $handler = $this->createMock(SaregisterChannelHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn($result);

            $command = new SaregisterCommand($handler);
            $messages = [];
            $outcome = $command->execute($this->context($this->sender(), ['#channel', 'User', 'Description'], $messages));

            self::assertFalse($outcome->success);
            self::assertNotEmpty($messages);
        }
    }

    private function sender(): SenderView
    {
        return new SenderView('U1', 'Oper', 'ident', 'host', 'cloak', '*', true, true, 'SID', 'host', 'o');
    }

    /**
     * @param list<string> $args
     * @param list<string> $messages
     */
    private function context(?SenderView $sender, array $args, array &$messages): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void { $messages[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return new ChanServContext(
            $sender,
            null,
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
            new ServiceNicknameRegistry([]),
        );
    }
}
