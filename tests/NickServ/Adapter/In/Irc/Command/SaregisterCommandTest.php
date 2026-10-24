<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Saregister\SaregisterNick;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickHandlerInterface;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickResult;
use App\NickServ\Domain\ValueObject\RegistrationMode;
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
        $command = new SaregisterCommand($this->createStub(SaregisterNickHandlerInterface::class));

        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.help', $command->getHelpKey());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame(68, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertSame([], $command->getHelpParams());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::SAREGISTER, $command->getRequiredPermission());
    }

    public function testInvalidArgumentsAndMissingSenderNeverInvokeTheHandler(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new SaregisterCommand($handler);
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');

        foreach ([[], ['Nick'], ['Nick', ''], ['Nick', 'pass', ''], ['Nick', 'pass', 'a@b.c', 'extra']] as $args) {
            $messages = [];
            self::assertFalse($command->execute($this->createContext($sender, $messages, $args))->success);
            self::assertSame(['error.syntax [%syntax%: saregister.syntax]'], $messages);
        }

        $messages = [];
        self::assertFalse($command->execute($this->createContext(null, $messages, ['Nick', 'pass']))->success);
        self::assertSame([], $messages);
    }

    public function testTokenModePresentsTheTokenOnceAndAuditsWithoutSecrets(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SaregisterNick $input): bool => 'Nick' === $input->nickname
                && 'plain' === $input->password
                && null === $input->email
                && 'en' === $input->language,
        ))->willReturn(SaregisterNickResult::registeredWithToken('Nick', RegistrationMode::Token, 'secret-token', null));

        $messages = [];
        $outcome = new SaregisterCommand($handler)->execute(
            $this->createContext(new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip'), $messages, ['Nick', 'plain']),
        );

        self::assertTrue($outcome->success);
        self::assertSame(['saregister.created_with_token [%nickname%: Nick, %token%: secret-token]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('Nick', $outcome->auditData->target);
        self::assertSame(['mode' => 'TOKEN'], $outcome->auditData->extra);
        self::assertStringNotContainsString('plain', $messages[0]);
    }

    public function testEmailModePresentsTheDeliveryAddress(): void
    {
        $messages = [];
        $outcome = new SaregisterCommand($this->handlerReturning(
            SaregisterNickResult::registered('Nick', RegistrationMode::Email, 'owner@example.com'),
        ))->execute($this->createContext(
            new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip'),
            $messages,
            ['Nick', 'plain', 'owner@example.com'],
        ));

        self::assertTrue($outcome->success);
        self::assertSame(['saregister.created [%email%: owner@example.com, %nickname%: Nick]'], $messages);
        self::assertSame(['mode' => 'EMAIL'], $outcome->auditData?->extra);
    }

    public function testMailDeliveryFailureStillAuditsTheCreatedAccount(): void
    {
        $messages = [];
        $outcome = new SaregisterCommand($this->handlerReturning(
            SaregisterNickResult::registeredWithoutMail('Nick', RegistrationMode::Email, 'owner@example.com'),
        ))->execute($this->createContext(
            new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip'),
            $messages,
            ['Nick', 'plain', 'owner@example.com'],
        ));

        self::assertTrue($outcome->success);
        self::assertSame(['saregister.created_without_mail [%email%: owner@example.com, %nickname%: Nick]'], $messages);
    }

    public function testRejectionsArePresentedAndNeverAudited(): void
    {
        $cases = [
            [SaregisterNickResult::invalidEmail('Nick', RegistrationMode::Email, null), 'register.invalid_email'],
            [SaregisterNickResult::emailAlreadyUsed('Nick', RegistrationMode::Email, 'a@b.c'), 'register.email_already_used [%email%: a@b.c]'],
            [SaregisterNickResult::alreadyPending('Nick', RegistrationMode::Token), 'register.already_pending [%nickname%: Nick]'],
            [SaregisterNickResult::forbidden('Nick', RegistrationMode::Token), 'register.forbidden [%nickname%: Nick]'],
            [SaregisterNickResult::pendingDeletion('Nick', RegistrationMode::Token), 'register.pending_deletion [%nickname%: Nick]'],
            [SaregisterNickResult::alreadyRegistered('Nick', RegistrationMode::Token), 'register.already_registered [%nickname%: Nick]'],
        ];

        foreach ($cases as [$result, $expected]) {
            $messages = [];
            $outcome = new SaregisterCommand($this->handlerReturning($result))->execute($this->createContext(
                new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip'),
                $messages,
                ['Nick', 'plain'],
            ));

            self::assertFalse($outcome->success);
            self::assertNull($outcome->auditData);
            self::assertSame([$expected], $messages);
        }
    }

    private function handlerReturning(SaregisterNickResult $result): SaregisterNickHandlerInterface
    {
        $handler = $this->createStub(SaregisterNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        return $handler;
    }

    /**
     * @param list<string> $messages
     * @param list<string> $args
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
            'SAREGISTER',
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
