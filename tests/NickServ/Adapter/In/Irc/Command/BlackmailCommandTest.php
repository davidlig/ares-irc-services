<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\BlackmailCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\BlockedEmailDomainRepositoryInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Domain\Entity\BlockedEmailDomain;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_splice;
use function count;

#[CoversClass(BlackmailCommand::class)]
final class BlackmailCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new BlackmailCommand(new InMemoryBlockedEmailDomainRepository(), $this->clock());

        self::assertSame('BLACKMAIL', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('blackmail.syntax', $command->getSyntaxKey());
        self::assertSame('blackmail.help', $command->getHelpKey());
        self::assertSame('blackmail.example', $command->getExampleKey());
        self::assertSame(75, $command->getOrder());
        self::assertSame('blackmail.short', $command->getShortDescKey());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::BLACKMAIL, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function getExampleKeyReturnsCorrectKey(): void
    {
        $command = new BlackmailCommand(new InMemoryBlockedEmailDomainRepository(), $this->clock());

        self::assertSame('blackmail.example', $command->getExampleKey());
    }

    #[Test]
    public function declaresTheThreeSubCommands(): void
    {
        $help = new BlackmailCommand(new InMemoryBlockedEmailDomainRepository(), $this->clock())->getSubCommandHelp();

        self::assertSame([
            ['name' => 'ADD', 'desc_key' => 'blackmail.add.short', 'help_key' => 'blackmail.add.help', 'syntax_key' => 'blackmail.add.syntax', 'example_key' => 'blackmail.add.example'],
            ['name' => 'DEL', 'desc_key' => 'blackmail.del.short', 'help_key' => 'blackmail.del.help', 'syntax_key' => 'blackmail.del.syntax', 'example_key' => 'blackmail.del.example'],
            ['name' => 'LIST', 'desc_key' => 'blackmail.list.short', 'help_key' => 'blackmail.list.help', 'syntax_key' => 'blackmail.list.syntax', 'example_key' => 'blackmail.list.example'],
        ], $help);
    }

    #[Test]
    public function addStoresTheNormalizedDomain(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['ADD', '  ExAmPle.COM '], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['blackmail.added'], $messages);
        self::assertCount(1, $repository->domains);
        self::assertSame('example.com', $repository->domains[0]->getDomain());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDomains(): iterable
    {
        yield 'no dot' => ['example'];
        yield 'at sign' => ['user@example.com'];
        yield 'leading dot' => ['.example.com'];
        yield 'trailing dot' => ['example.com.'];
        yield 'inner whitespace' => ['example com'];
    }

    #[Test]
    #[DataProvider('invalidDomains')]
    public function addRejectsInvalidDomains(string $domain): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['ADD', $domain], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.invalid_domain'], $messages);
        self::assertSame([], $repository->domains);
    }

    #[Test]
    public function addWithoutADomainReportsUsage(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['ADD'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.syntax', 'blackmail.usage'], $messages);
        self::assertSame([], $repository->domains);
    }

    #[Test]
    public function delWithoutAPositionReportsUsage(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['DEL'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.syntax', 'blackmail.usage'], $messages);
    }

    #[Test]
    public function addReportsAnExistingDomainWithoutDuplicatingIt(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $repository->domains[] = new BlockedEmailDomain('example.com', $this->clock()->now());
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['ADD', 'EXAMPLE.COM'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['blackmail.already_listed'], $messages);
        self::assertCount(1, $repository->domains);
    }

    #[Test]
    public function delRemovesTheDomainAtTheGivenPosition(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $repository->domains[] = new BlockedEmailDomain('alpha.com', $this->clock()->now());
        $repository->domains[] = new BlockedEmailDomain('beta.com', $this->clock()->now());
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['DEL', '2'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['blackmail.removed'], $messages);
        self::assertSame(['alpha.com'], array_map(
            static fn (BlockedEmailDomain $domain): string => $domain->getDomain(),
            $repository->domains,
        ));
    }

    #[Test]
    public function delAllClearsTheListAndReportsTheCount(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $repository->domains[] = new BlockedEmailDomain('alpha.com', $this->clock()->now());
        $repository->domains[] = new BlockedEmailDomain('beta.com', $this->clock()->now());
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['DEL', 'all'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['blackmail.removed_all'], $messages);
        self::assertSame([], $repository->domains);
    }

    #[Test]
    public function delReportsAnUnknownPosition(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $repository->domains[] = new BlockedEmailDomain('alpha.com', $this->clock()->now());
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['DEL', '9'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.not_found'], $messages);
        self::assertCount(1, $repository->domains);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPositions(): iterable
    {
        yield 'word' => ['first'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
    }

    #[Test]
    #[DataProvider('invalidPositions')]
    public function delRejectsNonNumericPositions(string $position): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['DEL', $position], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.invalid_number'], $messages);
    }

    #[Test]
    public function listPrintsEveryEntry(): void
    {
        $repository = new InMemoryBlockedEmailDomainRepository();
        $repository->domains[] = new BlockedEmailDomain('alpha.com', $this->clock()->now());
        $repository->domains[] = new BlockedEmailDomain('beta.com', $this->clock()->now());
        $messages = [];

        $outcome = $this->command($repository)->execute($this->context(['LIST'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['blackmail.list_header', 'blackmail.list_line', 'blackmail.list_line'], $messages);
    }

    #[Test]
    public function listReportsAnEmptyList(): void
    {
        $messages = [];

        $outcome = $this->command(new InMemoryBlockedEmailDomainRepository())->execute($this->context(['LIST'], $messages));

        self::assertTrue($outcome->success);
        self::assertSame(['blackmail.list_empty'], $messages);
    }

    #[Test]
    public function rejectsAnUnknownSubCommandWithUsage(): void
    {
        $messages = [];

        $outcome = $this->command(new InMemoryBlockedEmailDomainRepository())->execute($this->context(['NOPE'], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.syntax', 'blackmail.usage'], $messages);
    }

    #[Test]
    public function reportsUsageWithoutASubCommand(): void
    {
        $messages = [];

        $outcome = $this->command(new InMemoryBlockedEmailDomainRepository())->execute($this->context([], $messages));

        self::assertFalse($outcome->success);
        self::assertSame(['blackmail.syntax', 'blackmail.usage'], $messages);
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $messages = [];

        $outcome = $this->command(new InMemoryBlockedEmailDomainRepository())->execute(
            $this->context(['LIST'], $messages, withSender: false),
        );

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    private function command(BlockedEmailDomainRepositoryInterface $repository): BlackmailCommand
    {
        return new BlackmailCommand($repository, $this->clock());
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-19 12:00:00 UTC'));

        return $clock;
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

        return new NickServContext(
            $sender,
            null,
            'BLACKMAIL',
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

final class InMemoryBlockedEmailDomainRepository implements BlockedEmailDomainRepositoryInterface
{
    /** @var list<BlockedEmailDomain> */
    public array $domains = [];

    public function isBlocked(string $domain): bool
    {
        foreach ($this->domains as $entry) {
            if ($entry->getDomain() === $domain) {
                return true;
            }
        }

        return false;
    }

    public function add(BlockedEmailDomain $blockedEmailDomain): void
    {
        $this->domains[] = $blockedEmailDomain;
    }

    public function removeAtPosition(int $position): ?BlockedEmailDomain
    {
        if ($position < 1 || !isset($this->domains[$position - 1])) {
            return null;
        }

        $removed = $this->domains[$position - 1];
        array_splice($this->domains, $position - 1, 1);

        return $removed;
    }

    public function removeAll(): int
    {
        $count = count($this->domains);
        $this->domains = [];

        return $count;
    }

    public function listAll(): array
    {
        return $this->domains;
    }
}
