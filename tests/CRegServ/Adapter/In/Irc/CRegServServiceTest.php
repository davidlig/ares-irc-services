<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc;

use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationContextInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Adapter\In\Irc\CRegServService;
use App\CRegServ\Adapter\In\Irc\CRegServUserPresentationPreferences;
use App\CRegServ\Application\Port\Out\CRegServUserAccountPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\CRegServ\Fake\OperOnlyCommandDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CRegServService::class)]
final class CRegServServiceTest extends TestCase
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

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

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
     * An oper-only command that declares NO permission at all: the oper gate alone must stop it.
     */
    private function createOperOnlyCommand(): OperOnlyCommandDouble
    {
        return new OperOnlyCommandDouble();
    }

    private function createService(
        CRegServCommandInterface $command,
        CRegServNotifierInterface $notifier,
        ?CRegServAuthorizationCheckerInterface $authorizationChecker = null,
    ): CRegServService {
        return new CRegServService(
            commandRegistry: new CRegServCommandRegistry([$command]),
            userAccountPort: $this->createStub(CRegServUserAccountPort::class),
            languageResolver: $this->createPreferencesStub(),
            notifier: $notifier,
            messageTypeResolver: $this->createPreferencesStub(),
            translator: $this->createTranslator(),
            serviceNicks: $this->createServiceNicks(),
            authorizationContext: $this->createStub(CRegServAuthorizationContextInterface::class),
            // Default stub grants everything, so only the oper gate can be what rejects the command.
            authorizationChecker: $authorizationChecker ?? $this->createGrantedChecker(),
            eventDispatcher: $this->createStub(EventBusInterface::class),
        );
    }

    private function createPreferencesStub(): CRegServUserPresentationPreferences
    {
        $preferences = $this->createStub(CRegServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('en');
        $preferences->method('prefersPrivateMessages')->willReturn(false);

        return $preferences;
    }

    private function createGrantedChecker(): CRegServAuthorizationCheckerInterface
    {
        $checker = $this->createStub(CRegServAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);

        return $checker;
    }

    #[Test]
    public function operOnlyCommandIsRejectedForNonOperEvenWhenItDeclaresNoPermission(): void
    {
        $command = $this->createOperOnlyCommand();
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages));

        $service->dispatch('APROBAR #canal', new SenderView('UID1', 'JuanJo_Jaen', 'i', 'h', 'c', 'ip'));

        self::assertFalse($command->executed, 'An oper-only command must never run for a non-oper sender.');
        self::assertSame(['error.permission_denied'], $messages);
    }

    #[Test]
    public function operOnlyCommandIsExecutedForOper(): void
    {
        $command = $this->createOperOnlyCommand();
        $messages = [];
        $service = $this->createService($command, $this->createNotifier($messages));

        $service->dispatch(
            'APROBAR #canal',
            new SenderView('UID1', 'NetAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true),
        );

        self::assertTrue($command->executed);
        self::assertSame([], $messages);
    }
}
