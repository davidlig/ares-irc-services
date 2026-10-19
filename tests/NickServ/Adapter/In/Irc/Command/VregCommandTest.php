<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\VregCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\In\EmailVerificationPolicyInterface;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(VregCommand::class)]
final class VregCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new VregCommand(new RecordingEmailVerificationPolicy());

        self::assertSame('VREG', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('vreg.syntax', $command->getSyntaxKey());
        self::assertSame('vreg.help', $command->getHelpKey());
        self::assertSame('vreg.example', $command->getExampleKey());
        self::assertSame(74, $command->getOrder());
        self::assertSame('vreg.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::VREG, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    /** @param list<bool> $expectedCalls */
    #[Test]
    #[DataProvider('actions')]
    public function appliesTheRequestedAction(string $action, bool $initial, bool $expected, string $replyKey, array $expectedCalls): void
    {
        $messages = [];
        $policy = new RecordingEmailVerificationPolicy($initial);

        $outcome = new VregCommand($policy)->execute($this->context([$action], $messages));

        self::assertSame($expectedCalls, $policy->calls);
        self::assertSame($expected, $policy->required);
        self::assertSame([$replyKey], $messages);
        self::assertTrue($outcome->success);
    }

    /** @return iterable<string, array{string, bool, bool, string, list<bool>}> */
    public static function actions(): iterable
    {
        yield 'on' => ['ON', false, true, 'vreg.on', [true]];
        yield 'off' => ['OFF', true, false, 'vreg.off', [false]];
        yield 'status on' => ['STATUS', true, true, 'vreg.status_on', []];
        yield 'status off' => ['STATUS', false, false, 'vreg.status_off', []];
    }

    #[Test]
    public function acceptsLowercaseActions(): void
    {
        $messages = [];
        $policy = new RecordingEmailVerificationPolicy();

        new VregCommand($policy)->execute($this->context(['on'], $messages));

        self::assertSame([true], $policy->calls);
        self::assertSame(['vreg.on'], $messages);
    }

    #[Test]
    public function rejectsUnknownActions(): void
    {
        $messages = [];
        $policy = new RecordingEmailVerificationPolicy();

        $outcome = new VregCommand($policy)->execute($this->context(['MAYBE'], $messages));

        self::assertSame([], $policy->calls);
        self::assertSame(['vreg.syntax', 'vreg.usage'], $messages);
        self::assertFalse($outcome->success);
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $messages = [];
        $policy = new RecordingEmailVerificationPolicy();

        $outcome = new VregCommand($policy)->execute($this->context(['ON'], $messages, withSender: false));

        self::assertSame([], $policy->calls);
        self::assertSame([], $messages);
        self::assertFalse($outcome->success);
    }

    /** @param list<string> $arguments
     * @param list<string> $messages
     */
    private function context(array $arguments, array &$messages, bool $withSender = true): NickServContext
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key) use (&$messages): string {
            $messages[] = $key;

            return $key;
        });

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');

        $sender = $withSender
            ? new SenderView('001AAA', 'Oper', 'op', 'host', 'cloak', 'ip', true, true)
            : null;

        $account = null;
        if ($withSender) {
            $account = $this->createStub(RegisteredNick::class);
            $account->method('getId')->willReturn(42);
        }

        return new NickServContext(
            $sender,
            $account,
            'VREG',
            $arguments,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([$this->nicknameProvider()]),
        );
    }

    private function nicknameProvider(): ServiceNicknameProviderInterface
    {
        return new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
    }
}

final class RecordingEmailVerificationPolicy implements EmailVerificationPolicyInterface
{
    /** @var list<bool> */
    public array $calls = [];

    public function __construct(public bool $required = false) {}

    public function isVerificationRequired(): bool
    {
        return $this->required;
    }

    public function setVerificationRequired(bool $required): void
    {
        $this->calls[] = $required;
        $this->required = $required;
    }
}
