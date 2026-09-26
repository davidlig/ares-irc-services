<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\WhoipCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Whoip\FindNicknamesByLastConnectIp;
use App\NickServ\Application\UseCase\Whoip\FindNicknamesByLastConnectIpHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

#[CoversClass(WhoipCommand::class)]
final class WhoipCommandTest extends TestCase
{
    #[Test]
    public function exposesIrcopWhoipMetadata(): void
    {
        $command = new WhoipCommand($this->createStub(FindNicknamesByLastConnectIpHandlerInterface::class));

        self::assertSame('WHOIP', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('whoip.syntax', $command->getSyntaxKey());
        self::assertSame('whoip.help', $command->getHelpKey());
        self::assertSame(61, $command->getOrder());
        self::assertSame('whoip.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::WHOIP, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $messages = [];
        $outcome = new WhoipCommand($handler)->execute($this->createContext(null, $messages, ['192.0.2.1']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    #[DataProvider('invalidIpProvider')]
    public function rejectsInvalidIpWithSyntaxAndDoesNotQuery(string $ip): void
    {
        $handler = $this->createMock(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $messages = [];
        $outcome = new WhoipCommand($handler)->execute($this->createContext($this->sender(), $messages, [$ip]));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: whoip.syntax]'], $messages);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIpProvider(): iterable
    {
        yield 'hostname' => ['not-an-ip'];
        yield 'empty' => [''];
        yield 'invalid IPv4 octet' => ['192.0.2.256'];
        yield 'IPv4 subnet' => ['192.0.2.0/24'];
        yield 'IPv6 subnet' => ['2001:db8::/32'];
        yield 'IPv4 port' => ['192.0.2.1:6667'];
        yield 'bracketed IPv6' => ['[2001:db8::1]'];
        yield 'leading whitespace' => [' 192.0.2.1'];
    }

    #[Test]
    public function rejectsMissingArgumentWithSyntaxAndDoesNotQuery(): void
    {
        $handler = $this->createMock(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $messages = [];
        $outcome = new WhoipCommand($handler)->execute($this->createContext($this->sender(), $messages, []));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: whoip.syntax]'], $messages);
    }

    #[Test]
    public function rejectsExtraArgumentsWithSyntaxAndDoesNotQuery(): void
    {
        $handler = $this->createMock(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $messages = [];
        $outcome = new WhoipCommand($handler)->execute($this->createContext($this->sender(), $messages, ['192.0.2.1', 'extra']));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: whoip.syntax]'], $messages);
    }

    #[Test]
    public function canonicalizesIpv4AndPresentsAllNicknamesWithSafeAuditData(): void
    {
        $handler = $this->createMock(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new FindNicknamesByLastConnectIp('192.0.2.10')))
            ->willReturn(['Alice', 'PendingNick']);

        $messages = [];
        $outcome = new WhoipCommand($handler)->execute($this->createContext($this->sender(), $messages, ['192.0.2.10']));

        self::assertTrue($outcome->success);
        self::assertSame([
            'whoip.header',
            'Alice',
            'PendingNick',
        ], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('192.0.2.10', $outcome->auditData->target);
        self::assertSame('192.0.2.10', $outcome->auditData->targetIp);
        self::assertSame(['matches' => 2], $outcome->auditData->extra);
    }

    #[Test]
    public function presentsOneHeadingForASingleNickname(): void
    {
        $handler = $this->createStub(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->method('handle')->willReturn(['Alice']);
        $messages = [];

        new WhoipCommand($handler)->execute($this->createContext($this->sender(), $messages, ['192.0.2.10']));

        self::assertSame(['whoip.header', 'Alice'], $messages);
    }

    /** @return iterable<string, array{string, string}> */
    public static function localizedHeadings(): iterable
    {
        yield 'ca' => ['ca', 'Sobrenoms coincidents:'];
        yield 'de' => ['de', 'Übereinstimmende Nicknamen:'];
        yield 'el' => ['el', 'Ψευδώνυμα που ταιριάζουν:'];
        yield 'en' => ['en', 'Matching nicknames:'];
        yield 'es' => ['es', 'Apodos coincidentes:'];
        yield 'eu' => ['eu', 'Bat datozen ezizenak:'];
        yield 'fr' => ['fr', 'Surnoms correspondants :'];
        yield 'gl' => ['gl', 'Alcumes coincidentes:'];
        yield 'it' => ['it', 'Nickname corrispondenti:'];
        yield 'nl' => ['nl', 'Overeenkomende nicknames:'];
        yield 'pl' => ['pl', 'Pasujące pseudonimy:'];
        yield 'pt' => ['pt', 'Nicknames correspondentes:'];
        yield 'ro' => ['ro', 'Nickname-uri corespunzătoare:'];
        yield 'tr' => ['tr', 'Eşleşen takma adlar:'];
    }

    #[Test]
    #[DataProvider('localizedHeadings')]
    public function rendersLocalizedHeadingOnceAndLiteralNicknames(string $locale, string $heading): void
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/../../../../../../translations/nickserv.' . $locale . '.yaml', $locale, 'nickserv');
        $handler = $this->createStub(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->method('handle')->willReturn(['Alice', 'PendingNick']);
        $messages = [];

        new WhoipCommand($handler)->execute($this->createContext($this->sender(), $messages, ['192.0.2.10'], $translator, $locale));

        self::assertSame([$heading, 'Alice', 'PendingNick'], $messages);
    }

    #[Test]
    public function canonicalizesIpv6AndReportsEmptyMatches(): void
    {
        $handler = $this->createMock(FindNicknamesByLastConnectIpHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new FindNicknamesByLastConnectIp('2001:db8::1')))
            ->willReturn([]);

        $messages = [];
        $outcome = new WhoipCommand($handler)->execute($this->createContext(
            $this->sender(),
            $messages,
            ['2001:0db8:0000:0000:0000:0000:0000:0001'],
        ));

        self::assertTrue($outcome->success);
        self::assertSame(['whoip.empty'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('2001:db8::1', $outcome->auditData->target);
        self::assertSame('2001:db8::1', $outcome->auditData->targetIp);
        self::assertSame(['matches' => 0], $outcome->auditData->extra);
    }

    private function sender(): SenderView
    {
        return new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
    }

    /**
     * @param list<string> $messages
     * @param list<string> $args
     */
    private function createContext(?SenderView $sender, array &$messages, array $args, ?TranslatorInterface $catalogTranslator = null, string $locale = 'en'): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if (isset($params['%syntax%']) && is_string($params['%syntax%'])) {
                return $id . ' [%syntax%: ' . $params['%syntax%'] . ']';
            }
            if (isset($params['%nickname%']) && is_string($params['%nickname%'])) {
                return $id . ' [%nickname%: ' . $params['%nickname%'] . ']';
            }

            return $id;
        });

        return new NickServContext(
            $sender,
            null,
            'WHOIP',
            $args,
            $notifier,
            $catalogTranslator ?? $translator,
            $locale,
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([]),
        );
    }
}
