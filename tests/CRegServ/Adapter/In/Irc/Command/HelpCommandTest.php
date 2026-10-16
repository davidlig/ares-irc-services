<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\Command\HelpCommand;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function in_array;
use function is_scalar;
use function strtolower;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    /**
     * @param list<string> $messages
     */
    private function createNotifier(array &$messages): CRegServNotifierInterface
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CReG');
        $notifier->method('sendMessage')->willReturnCallback(
            static function (string $target, string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        return $notifier;
    }

    /** Returns "key:param1|param2" so assertions can see which command a line describes. */
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $params = []): string {
                $parts = [];
                foreach ($params as $value) {
                    $parts[] = is_scalar($value) ? (string) $value : get_debug_type($value);
                }

                return [] === $parts ? $id : $id . ':' . implode('|', $parts);
            },
        );

        return $translator;
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'cregserv';
            }

            public function getNickname(): string
            {
                return 'CReG';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    /**
     * @param list<string> $args
     */
    private function createContext(
        SenderView $sender,
        array $args,
        CRegServNotifierInterface $notifier,
        CRegServCommandRegistry $registry,
    ): CRegServContext {
        return new CRegServContext(
            $sender,
            new CRegServAccountView(7, 'JuanJo_Jaen', 'en'),
            'HELP',
            $args,
            $notifier,
            $this->createTranslator(),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
    }

    /**
     * @param list<string>                   $grantedPermissions
     * @param list<CRegServCommandInterface> $commands
     */
    private function createHelpCommand(array $commands, array $grantedPermissions = []): HelpCommand
    {
        $checker = $this->createStub(CRegServAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (string $permission): bool => in_array($permission, $grantedPermissions, true),
        );

        return new HelpCommand(new UnifiedHelpFormatter(), $checker);
    }

    private function createCommandStub(string $name, int $order, bool $operOnly, ?string $permission): CRegServCommandInterface
    {
        $command = $this->createStub(CRegServCommandInterface::class);
        $command->method('getName')->willReturn($name);
        $command->method('getAliases')->willReturn([]);
        $command->method('getOrder')->willReturn($order);
        $command->method('isOperOnly')->willReturn($operOnly);
        $command->method('getRequiredPermission')->willReturn($permission);
        $command->method('getShortDescKey')->willReturn(strtolower($name) . '.short');
        $command->method('getHelpKey')->willReturn(strtolower($name) . '.help');
        $command->method('getSyntaxKey')->willReturn(strtolower($name) . '.syntax');
        $command->method('getSubCommandHelp')->willReturn([]);

        return $command;
    }

    /**
     * @param list<CRegServCommandInterface> $commands
     */
    private function createRegistry(HelpCommand $help, array $commands): CRegServCommandRegistry
    {
        return new CRegServCommandRegistry([...$commands, $help]);
    }

    private function plainUser(): SenderView
    {
        return new SenderView('UID1', 'JuanJo_Jaen', 'i', 'h', 'c', 'ip', true);
    }

    private function operUser(): SenderView
    {
        return new SenderView('UID2', 'OperNick', 'i', 'h', 'c', 'ip', true, true);
    }

    #[Test]
    public function metadataMatchesTheUnifiedHelpConvention(): void
    {
        $help = $this->createHelpCommand([]);

        self::assertSame('HELP', $help->getName());
        self::assertSame(['?'], $help->getAliases());
        self::assertSame(0, $help->getMinArgs());
        self::assertSame(99, $help->getOrder());
        self::assertSame('help.syntax', $help->getSyntaxKey());
        self::assertSame('help.help', $help->getHelpKey());
        self::assertSame('help.short', $help->getShortDescKey());
        self::assertFalse($help->isOperOnly());
        self::assertNull($help->getRequiredPermission());
    }

    #[Test]
    public function generalHelpListsUserCommandsAndHidesTheOperOnesFromAPlainUser(): void
    {
        $registra = $this->createCommandStub('REGISTRA', 1, false, 'IDENTIFIED');
        $estado = $this->createCommandStub('ESTADO', 2, false, 'IDENTIFIED');
        $aprobar = $this->createCommandStub('APROBAR', 3, true, 'cregserv.aprobar');
        $help = $this->createHelpCommand([$registra, $estado, $aprobar]);

        $messages = [];
        $notifier = $this->createNotifier($messages);
        $context = $this->createContext($this->plainUser(), [], $notifier, $this->createRegistry($help, [$registra, $estado, $aprobar]));

        $help->execute($context);

        self::assertCount(1, $messages);
        $output = $messages[0];
        self::assertStringContainsString('help.intro', $output);
        self::assertStringContainsString('help.general_header', $output);
        self::assertStringContainsString('REGISTRA', $output);
        self::assertStringContainsString('ESTADO', $output);
        self::assertStringNotContainsString('help.ircop_header', $output);
        self::assertStringNotContainsString('APROBAR', $output);
        self::assertStringContainsString('help.footer', $output);
    }

    #[Test]
    public function generalHelpShowsTheOperSectionToAnOperHoldingThePermission(): void
    {
        $registra = $this->createCommandStub('REGISTRA', 1, false, 'IDENTIFIED');
        $aprobar = $this->createCommandStub('APROBAR', 3, true, 'cregserv.aprobar');
        $drop = $this->createCommandStub('DROP', 6, true, 'cregserv.drop');
        $help = $this->createHelpCommand([$registra, $aprobar, $drop], ['cregserv.aprobar']);

        $messages = [];
        $notifier = $this->createNotifier($messages);
        $context = $this->createContext($this->operUser(), [], $notifier, $this->createRegistry($help, [$registra, $aprobar, $drop]));

        $help->execute($context);

        self::assertCount(1, $messages);
        $output = $messages[0];
        self::assertStringContainsString('help.ircop_header', $output);
        self::assertStringContainsString('APROBAR', $output);
        self::assertStringNotContainsString('DROP', $output, 'A command whose permission the oper lacks must stay hidden.');
    }

    #[Test]
    public function helpForACommandRendersItsHelpTextAndSyntax(): void
    {
        $registra = $this->createCommandStub('REGISTRA', 1, false, 'IDENTIFIED');
        $help = $this->createHelpCommand([$registra]);

        $messages = [];
        $notifier = $this->createNotifier($messages);
        $context = $this->createContext($this->plainUser(), ['REGISTRA'], $notifier, $this->createRegistry($help, [$registra]));

        $help->execute($context);

        self::assertCount(1, $messages);
        $output = $messages[0];
        self::assertStringContainsString('registra.help', $output);
        self::assertStringContainsString('help.syntax_label', $output);
        self::assertStringContainsString('registra.syntax', $output);
    }

    #[Test]
    public function helpForAnUnknownCommandRepliesWithTheTranslatedError(): void
    {
        $help = $this->createHelpCommand([]);

        $messages = [];
        $notifier = $this->createNotifier($messages);
        $context = $this->createContext($this->plainUser(), ['NOSUCH'], $notifier, $this->createRegistry($help, []));

        $help->execute($context);

        self::assertCount(1, $messages);
        self::assertStringContainsString('help.unknown_command', $messages[0]);
    }
}
