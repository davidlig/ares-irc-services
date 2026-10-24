<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\RegmodeCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeHandlerInterface;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeInput;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeResult;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_scalar;

#[CoversClass(RegmodeCommand::class)]
final class RegmodeCommandTest extends TestCase
{
    public function testMetadata(): void
    {
        $command = new RegmodeCommand($this->createStub(RegistrationModeHandlerInterface::class), $this->createStub(NickServOperatorAccess::class));
        self::assertSame('REGMODE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('regmode.syntax', $command->getSyntaxKey());
        self::assertSame('regmode.help', $command->getHelpKey());
        self::assertSame('regmode.example', $command->getExampleKey());
        self::assertSame('regmode.short', $command->getShortDescKey());
        self::assertSame(67, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertSame([], $command->getHelpParams());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::REGMODE, $command->getRequiredPermission());
    }

    public function testInvalidArgumentsAndMissingSenderNeverInvokeHandler(): void
    {
        $handler = $this->createMock(RegistrationModeHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new RegmodeCommand($handler, $this->createStub(NickServOperatorAccess::class));
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        foreach ([[], ['NO'], ['TOKEN', 'extra']] as $args) {
            $messages = [];
            self::assertFalse($command->execute($this->createContext($sender, $messages, $args))->success);
            self::assertSame(['error.syntax [%syntax%: regmode.syntax]'], $messages);
        }
        $messages = [];
        self::assertFalse($command->execute($this->createContext(null, $messages, ['TOKEN']))->success);
        self::assertSame([], $messages);
    }

    public function testAuthorizationDenialIsPresented(): void
    {
        $access = $this->createMock(NickServOperatorAccess::class);
        $access->expects(self::once())->method('hasPermission')->with('oper', null, false, false, NickServPermission::REGMODE)->willReturn(false);
        $handler = $this->createMock(RegistrationModeHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(static fn (RegistrationModeInput $input): bool => !$input->authorized && RegistrationMode::Email === $input->mode))->willReturn(new RegistrationModeResult(false));
        $messages = [];
        $outcome = new RegmodeCommand($handler, $access)->execute($this->createContext(new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip'), $messages, ['email']));
        self::assertFalse($outcome->success);
        self::assertSame(['error.permission_denied'], $messages);
    }

    public function testStatusAndNoopHaveNoAuditButChangeDoes(): void
    {
        $access = $this->createStub(NickServOperatorAccess::class);
        $access->method('hasPermission')->willReturn(true);
        $handler = $this->createMock(RegistrationModeHandlerInterface::class);
        $handler->expects(self::exactly(3))->method('handle')->with(self::callback(static fn (RegistrationModeInput $input): bool => $input->authorized))->willReturnOnConsecutiveCalls(new RegistrationModeResult(true, RegistrationMode::Token), new RegistrationModeResult(true, RegistrationMode::Token), new RegistrationModeResult(true, RegistrationMode::Email, true));
        $command = new RegmodeCommand($handler, $access);
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        foreach ([['STATUS'], ['TOKEN']] as $args) {
            $messages = [];
            $outcome = $command->execute($this->createContext($sender, $messages, $args));
            self::assertTrue($outcome->success);
            self::assertNull($outcome->auditData);
            self::assertSame(['regmode.status [%mode%: TOKEN]'], $messages);
        }
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['EMAIL']));
        self::assertTrue($outcome->success);
        self::assertNotNull($outcome->auditData);
        self::assertSame('network', $outcome->auditData->target);
        self::assertSame(['option' => 'REGMODE', 'value' => 'EMAIL'], $outcome->auditData->extra);
        self::assertSame(['regmode.changed [%mode%: EMAIL]'], $messages);
    }

    /**
     * @param string[] $messages
     * @param string[] $args
     */
    private function createContext(?SenderView $sender, array &$messages, array $args): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            unset($params['%bot%'], $params['%nickserv%']);
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

        return new NickServContext(
            $sender,
            null,
            'REGMODE',
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
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }
}
