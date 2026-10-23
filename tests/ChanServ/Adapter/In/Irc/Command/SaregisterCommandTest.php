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
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_scalar;

#[CoversClass(SaregisterCommand::class)]
final class SaregisterCommandTest extends TestCase
{
    public function testMetadata(): void
    {
        $command = new SaregisterCommand($this->createStub(SaregisterChannelHandlerInterface::class));

        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(3, $command->getMinArgs());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.help', $command->getHelpKey());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame(82, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame(ChanServPermission::SAREGISTER, $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    public function testInvalidArgumentsNeverInvokeTheHandler(): void
    {
        $handler = $this->createMock(SaregisterChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new SaregisterCommand($handler);

        $messages = [];
        self::assertFalse($command->execute($this->createContext($messages, ['room', 'Founder', 'description']))->success);
        self::assertSame(['error.invalid_channel'], $messages);

        $messages = [];
        self::assertFalse($command->execute($this->createContext($messages, ['#room']))->success);
        self::assertSame(['error.syntax [%syntax%: saregister.syntax]'], $messages);

        $messages = [];
        self::assertFalse($command->execute($this->createContext($messages, ['#room', 'Founder', '   ']))->success);
        self::assertSame(['error.syntax [%syntax%: saregister.syntax]'], $messages);
    }

    public function testRegistersTheChannelAndAuditsOperatorChannelAndFounder(): void
    {
        $handler = $this->createMock(SaregisterChannelHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SaregisterChannel $input): bool => '#Room' === $input->channelName
                && 'Founder' === $input->founderNickname
                && 'Migration from another services package' === $input->description
                && 'Oper' === $input->actorNickname,
        ))->willReturn(SaregisterChannelResult::registered('#Room', 'Founder'));

        $messages = [];
        $outcome = new SaregisterCommand($handler)->execute($this->createContext(
            $messages,
            ['#Room', 'Founder', 'Migration', 'from', 'another', 'services', 'package'],
        ));

        self::assertTrue($outcome->success);
        self::assertSame(['saregister.registered [%channel%: #Room, %nickname%: Founder]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('#Room', $outcome->auditData->target);
        self::assertSame(['founder' => 'Founder'], $outcome->auditData->extra);
    }

    public function testRejectionsArePresentedAndNeverAudited(): void
    {
        $cases = [
            [SaregisterChannelResult::alreadyRegistered('#Room', 'Founder'), 'saregister.already_registered [%channel%: #Room]'],
            [SaregisterChannelResult::pendingDeletion('#Room', 'Founder'), 'saregister.pending_deletion [%channel%: #Room]'],
            [SaregisterChannelResult::founderNotFound('#Room', 'Founder'), 'saregister.founder_not_found [%nickname%: Founder]'],
            [SaregisterChannelResult::founderInactive('#Room', 'Founder'), 'saregister.founder_inactive [%nickname%: Founder]'],
        ];

        foreach ($cases as [$result, $expected]) {
            $messages = [];
            $outcome = new SaregisterCommand($this->handlerReturning($result))->execute(
                $this->createContext($messages, ['#Room', 'Founder', 'description']),
            );

            self::assertFalse($outcome->success);
            self::assertNull($outcome->auditData);
            self::assertSame([$expected], $messages);
        }
    }

    private function handlerReturning(SaregisterChannelResult $result): SaregisterChannelHandlerInterface
    {
        $handler = $this->createStub(SaregisterChannelHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        return $handler;
    }

    /**
     * @param list<string> $messages
     * @param list<string> $args
     */
    private function createContext(array &$messages, array $args): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            unset($params['%bot%'], $params['%chanserv%']);
            if ([] === $params) {
                return $id;
            }
            ksort($params);
            $formatted = [];
            foreach ($params as $key => $value) {
                $formatted[] = (string) $key . ': ' . (is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
            }

            return $id . ' [' . implode(', ', $formatted) . ']';
        });

        return new ChanServContext(
            new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip'),
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
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'chanserv';
            }

            public function getNickname(): string
            {
                return 'ChanServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }
}
