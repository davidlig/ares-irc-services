<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\Command\IgnoreCommand;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationCheckerInterface;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationContextInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Adapter\In\Irc\MemoServService;
use App\MemoServ\Adapter\In\Irc\MemoServUserPresentationPreferences;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemo;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoResult;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

use function array_keys;
use function dirname;

#[CoversNothing]
final class MemoServArgumentRoutingTest extends TestCase
{
    public function testIgnoreDefinitionDescribesConditionalChannelAndSpecificSyntax(): void
    {
        $command = new IgnoreCommand($this->createStub(IgnoreMemoHandlerInterface::class), 10, 20);
        $definition = $command->getArgumentDefinition();
        self::assertSame('ignore.syntax', $definition->syntaxKey);
        self::assertSame(['ADD', 'DEL', 'LIST'], array_keys($definition->variants));
        self::assertSame('ignore.add.syntax', CommandArgumentValidator::validate($definition, ['ADD', '#room'])->syntaxKey);
        self::assertTrue(CommandArgumentValidator::validate($definition, ['ADD', '#room', 'Bob'])->isValid());
        self::assertTrue(CommandArgumentValidator::validate($definition, ['ADD', 'Bob', 'legacy'])->isValid());
        self::assertTrue(CommandArgumentValidator::validate($definition, ['LIST'])->isValid());
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingTargets(): iterable
    {
        yield 'option first' => ['IGNORE ADD', 'ignore.add.syntax'];
        yield 'optional channel' => ['IGNORE ADD #room', 'ignore.add.syntax'];
        yield 'delete channel target' => ['IGNORE DEL #room', 'ignore.del.syntax'];
        yield 'unknown option' => ['IGNORE UNKNOWN', 'ignore.syntax'];
    }

    #[DataProvider('missingTargets')]
    public function testDispatchPresentsSyntaxWithoutInvokingMutation(string $input, string $key): void
    {
        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];
        $translator = $this->translator();
        $this->service($handler, $translator, $messages)->dispatch($input, $this->sender());
        $expected = $translator->trans('error.syntax', ['%syntax%' => $translator->trans($key, [], 'memoserv')], 'common');
        self::assertSame([$expected], $messages);
    }

    public function testAuthorizationStillWinsOverSpecificSyntax(): void
    {
        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];
        $translator = $this->translator();
        $this->service($handler, $translator, $messages, false)->dispatch('IGNORE ADD #room', $this->sender());
        self::assertSame([$translator->trans('error.not_identified', [], 'common')], $messages);
    }

    public function testDispatchPreservesChannelTargetAndIgnoredSurplusArguments(): void
    {
        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (IgnoreMemo $input): bool => 1 === $input->senderNickId && '#room' === $input->channelName && 'Bob' === $input->targetNick,
        ))->willReturn(IgnoreMemoResult::addedChannel('#room', 'Bob'));
        $messages = [];
        $translator = $this->translator();
        $this->service($handler, $translator, $messages)->dispatch('IGNORE add #room Bob legacy', $this->sender());
        self::assertSame([$translator->trans('ignore.added', ['%nickname%' => 'Bob', '%nick%' => 'Bob'], 'memoserv')], $messages);
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['memoserv', 'common'] as $domain) {
            $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/' . $domain . '.en.yaml', 'en', $domain);
        }

        return $translator;
    }

    /** @param list<string> $messages */
    private function service(IgnoreMemoHandlerInterface $handler, Translator $translator, array &$messages, bool $authorized = true): MemoServService
    {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $account = $this->createStub(MemoUserAccountPort::class);
        $account->method('findAccountByNick')->willReturn(new MemoAccountView(1, 'Alice', 'en'));
        $preferences = $this->createStub(MemoServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('en');
        $authorization = $this->createStub(MemoAuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($authorized);
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('memoserv');
        $provider->method('getNickname')->willReturn('MemoServ');

        return new MemoServService(
            new MemoServCommandRegistry([new IgnoreCommand($handler, 10, 20)]),
            $account,
            $preferences,
            $notifier,
            $preferences,
            $translator,
            new ServiceNicknameRegistry([$provider]),
            $this->createStub(MemoAuthorizationContextInterface::class),
            $authorization,
            $this->createStub(EventBusInterface::class)
        );
    }

    private function sender(): SenderView
    {
        return new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', '*', true, false, '001');
    }
}
