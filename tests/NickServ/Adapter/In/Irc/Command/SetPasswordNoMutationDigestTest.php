<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SetCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Set\SetNickSetting;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandlerInterface;
use App\NickServ\Application\UseCase\Set\SetNickSettingOutcome;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash;
use function is_string;
use function serialize;

#[CoversClass(SetCommand::class)]
final class SetPasswordNoMutationDigestTest extends TestCase
{
    public function testMissingPasswordValueLeavesSeededFakeStateUntouched(): void
    {
        $state = ['accounts' => ['Alice' => ['password_hash' => 'seeded-hash']], 'audit' => []];
        $mutatorCalls = 0;
        $mutate = static function (SetNickSetting $input) use (&$state, &$mutatorCalls): void {
            ++$mutatorCalls;
            $state['accounts'][$input->targetNickname]['password_hash'] = 'mutated-hash';
            $state['audit'][] = 'set-password';
        };
        $handler = new class($mutate) implements SetNickSettingHandlerInterface {
            /** @param Closure(SetNickSetting): void $mutate */
            public function __construct(private readonly Closure $mutate) {}

            public function handle(SetNickSetting $input): SetNickSettingResult
            {
                ($this->mutate)($input);

                return new SetNickSettingResult(SetNickSettingOutcome::Changed, $input->option, $input->targetNickname, $input->value);
            }
        };
        $before = hash('sha256', serialize($state));
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(
            static function (string $target, string $message) use (&$messages): void { $messages[] = $message; },
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key, array $parameters = []): string => 'error.syntax' === $key
                ? 'error.syntax|' . (is_string($parameters['%syntax%'] ?? null) ? $parameters['%syntax%'] : '')
                : $key,
        );
        $context = new NickServContext(
            new SenderView('UID1', 'Alice', 'ident', 'host.test', 'cloak.test', '*', true, false, 'SID1'),
            null,
            'SET',
            ['PASSWORD'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([]),
        );

        new SetCommand($handler)->execute($context);

        $after = hash('sha256', serialize($state));
        self::assertSame($before, $after);
        self::assertSame(0, $mutatorCalls);
        self::assertSame(['error.syntax|set.password.syntax'], $messages);
    }
}
