<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterIrcopTest extends TestCase
{
    private function createIrcopCommandStub(string $name, string $permission): NickServCommandInterface
    {
        return new class($name, $permission) implements NickServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $permission,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return $this->permission;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                return null;
            }
        };
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }

    #[Test]
    public function getIrcopCommandsReturnsCommandsAllowedByPublicBoundary(): void
    {
        $cmd1 = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $cmd2 = $this->createIrcopCommandStub('INFO', 'nickserv.info');
        $context = $this->context(new NickServCommandRegistry([$cmd1, $cmd2]), identified: true, oper: true, account: true);
        $access = $this->createStub(NickServOperatorAccess::class);
        $access->method('hasPermission')->willReturn(true);

        $commands = iterator_to_array(new HelpFormatterContextAdapter($context, $access)->getIrcopCommands());

        self::assertSame([$cmd1, $cmd2], $commands);
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyWithoutIdentifiedAccount(): void
    {
        $cmd = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $access = $this->createMock(NickServOperatorAccess::class);
        $access->expects(self::never())->method('hasPermission');

        $withoutAccount = new HelpFormatterContextAdapter(
            $this->context(new NickServCommandRegistry([$cmd]), identified: true, oper: true, account: false),
            $access,
        );
        $notIdentified = new HelpFormatterContextAdapter(
            $this->context(new NickServCommandRegistry([$cmd]), identified: false, oper: true, account: true),
            $access,
        );

        self::assertSame([], iterator_to_array($withoutAccount->getIrcopCommands()));
        self::assertSame([], iterator_to_array($notIdentified->getIrcopCommands()));
    }

    #[Test]
    public function getIrcopCommandsFiltersUsingOnlyNickServBoundary(): void
    {
        $allowed = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $denied = $this->createIrcopCommandStub('OTHER', 'nickserv.other');
        $access = $this->createStub(NickServOperatorAccess::class);
        $access->method('hasPermission')->willReturnCallback(
            static fn (string $nick, ?int $id, bool $identified, bool $oper, string $permission): bool => 'operuser' === $nick && 1 === $id && $identified && $oper && 'nickserv.userip' === $permission,
        );
        $adapter = new HelpFormatterContextAdapter(
            $this->context(new NickServCommandRegistry([$allowed, $denied]), identified: true, oper: true, account: true),
            $access,
        );

        self::assertSame([$allowed], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function hasIrcopAccessDelegatesToPublicBoundary(): void
    {
        $access = $this->createMock(NickServOperatorAccess::class);
        $access->expects(self::once())->method('hasAnyPermission')->willReturn(true);
        $adapter = new HelpFormatterContextAdapter(
            $this->context(new NickServCommandRegistry([]), identified: true, oper: true, account: true),
            $access,
        );

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseWithoutIdentifiedAccount(): void
    {
        $access = $this->createMock(NickServOperatorAccess::class);
        $access->expects(self::never())->method('hasAnyPermission');

        self::assertFalse(new HelpFormatterContextAdapter(
            $this->context(new NickServCommandRegistry([]), identified: false, oper: true, account: true),
            $access,
        )->hasIrcopAccess());
    }

    #[Test]
    public function shouldHideNickServOperatorCommandFromGeneralHelp(): void
    {
        $command = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $adapter = new HelpFormatterContextAdapter(
            $this->context(new NickServCommandRegistry([]), identified: false, oper: false, account: false),
            $this->createStub(NickServOperatorAccess::class),
        );

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($command));
    }

    private function context(
        NickServCommandRegistry $registry,
        bool $identified,
        bool $oper,
        bool $account,
    ): NickServContext {
        $registeredNick = null;
        if ($account) {
            $registeredNick = $this->createStub(RegisteredNick::class);
            $registeredNick->method('getId')->willReturn(1);
        }

        return new NickServContext(
            new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', $identified, $oper),
            $registeredNick,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }
}
