<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Service;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\Service\NickServDebugNotifier;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NickServDebugNotifier::class)]
final class NickServDebugNotifierTest extends TestCase
{
    private function createNotifier(
        ?string $debugChannel = '#opers',
        ?NickServNotifierInterface $notifier = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?NickServOperatorAccess $operatorAccess = null,
        ?TranslatorInterface $translator = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?IdentifiedSessionRegistry $identifiedRegistry = null,
        string $language = 'en',
    ): NickServDebugNotifier {
        return new NickServDebugNotifier(
            $notifier ?? $this->createStub(NickServNotifierInterface::class),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $identifiedRegistry ?? new IdentifiedSessionRegistry(),
            $operatorAccess ?? $this->createStub(NickServOperatorAccess::class),
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            $language,
            $debugChannel,
        );
    }

    #[Test]
    public function getServiceNameReturnsNickserv(): void
    {
        $notifier = $this->createNotifier();

        self::assertSame('nickserv', $notifier->getServiceName());
    }

    #[Test]
    public function getUserLookupReturnsConfiguredLookup(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $notifier = $this->createNotifier(userLookup: $userLookup);

        self::assertSame($userLookup, $notifier->getUserLookup());
    }

    #[Test]
    public function getIdentifiedRegistryReturnsConfiguredRegistry(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createNotifier(identifiedRegistry: $identifiedRegistry);

        self::assertSame($identifiedRegistry, $notifier->getIdentifiedRegistry());
    }

    #[Test]
    public function isConfiguredReturnsTrueWhenChannelIsSet(): void
    {
        $notifier = $this->createNotifier('#opers');

        self::assertTrue($notifier->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsNull(): void
    {
        $notifier = $this->createNotifier(null);

        self::assertFalse($notifier->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsEmpty(): void
    {
        $notifier = $this->createNotifier('');

        self::assertFalse($notifier->isConfigured());
    }

    #[Test]
    public function logDoesNotSendMessageWhenChannelNotConfigured(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createNotifier(null, $notifier);

        $debug->log(
            operator: 'Admin',
            command: 'SASET',
            target: 'TargetUser',
            extra: ['option' => 'VHOST', 'value' => 'test.com'],
        );
    }

    #[Test]
    public function notifySendsRawMessageWhenConfigured(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')
            ->with('#opers', 'raw debug message', 'NOTICE');

        $debug = $this->createNotifier('#opers', $notifier);

        $debug->notify('raw debug message');
    }

    #[Test]
    public function notifyDoesNothingWhenNotConfigured(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createNotifier(null, $notifier);

        $debug->notify('raw debug message');
    }

    #[Test]
    public function logSendsMessageToChannelWhenConfigured(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with('#opers', self::stringContains('formatted'), 'NOTICE');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())
            ->method('trans')
            ->willReturn('formatted message');

        $debug = $this->createNotifier('#opers', $notifier, translator: $translator);

        $debug->log(
            operator: 'Admin',
            command: 'SASET',
            target: 'TargetUser',
            reason: 'Test reason',
            extra: ['option' => 'VHOST', 'value' => 'test.com'],
        );
    }

    #[Test]
    public function logHidesPasswordValue(): void
    {
        $capturedMessage = '';

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale): string {
                if ('debug.action_with_option' === $id) {
                    self::assertIsString($params['%operator%']);
                    self::assertIsString($params['%command%']);
                    self::assertIsString($params['%target%']);
                    self::assertIsString($params['%option%']);

                    return $params['%operator%'] . ' ' . $params['%command%'] . ' ' . $params['%target%'] . ' ' . $params['%option%'];
                }

                return '';
            });

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->willReturnCallback(static function (string $channel, string $message, string $type) use (&$capturedMessage): void {
                $capturedMessage = $message;
            });

        $debug = $this->createNotifier('#opers', $notifier, translator: $translator);

        $debug->log(
            operator: 'Admin',
            command: 'SASET',
            target: 'TargetUser',
            extra: ['option' => 'PASSWORD', 'value' => 'secret123'],
        );

        self::assertStringContainsString('PASSWORD', $capturedMessage);
        self::assertStringNotContainsString('secret123', $capturedMessage);
    }

    #[Test]
    public function logShowsOptionAndValue(): void
    {
        $capturedMessage = '';

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale): string {
                if ('debug.action_with_value' === $id) {
                    self::assertIsString($params['%option%']);
                    self::assertIsString($params['%value%']);

                    return $params['%option%'] . '=' . $params['%value%'];
                }

                return '';
            });

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->willReturnCallback(static function (string $channel, string $message, string $type) use (&$capturedMessage): void {
                $capturedMessage = $message;
            });

        $debug = $this->createNotifier('#opers', $notifier, translator: $translator);

        $debug->log(
            operator: 'Admin',
            command: 'SASET',
            target: 'TargetUser',
            extra: ['option' => 'EMAIL', 'value' => 'test@example.com'],
        );

        self::assertStringContainsString('EMAIL=test@example.com', $capturedMessage);
    }

    #[Test]
    public function logShowsOptionWithoutValue(): void
    {
        $capturedMessage = '';

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale): string {
                if ('debug.action_with_option' === $id) {
                    self::assertIsString($params['%operator%']);
                    self::assertIsString($params['%command%']);
                    self::assertIsString($params['%target%']);
                    self::assertIsString($params['%option%']);

                    return $params['%operator%'] . ' ' . $params['%command%'] . ' ' . $params['%target%'] . ' Option=' . $params['%option%'];
                }

                return '';
            });

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->willReturnCallback(static function (string $channel, string $message, string $type) use (&$capturedMessage): void {
                $capturedMessage = $message;
            });

        $debug = $this->createNotifier('#opers', $notifier, translator: $translator);

        $debug->log(
            operator: 'Admin',
            command: 'NOEXPIRE',
            target: 'TargetUser',
            extra: ['option' => 'ON'],
        );

        self::assertStringContainsString('Option=ON', $capturedMessage);
    }

    #[Test]
    public function logWithDuration(): void
    {
        $capturedMessage = '';

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale): string {
                if ('debug.action_duration' === $id) {
                    self::assertIsString($params['%duration%']);

                    return 'dur=' . $params['%duration%'];
                }
                if ('debug.prefix_reason' === $id) {
                    self::assertIsString($params['%reason%']);

                    return 'r=' . $params['%reason%'];
                }

                return '';
            });

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->willReturnCallback(static function (string $channel, string $message, string $type) use (&$capturedMessage): void {
                $capturedMessage = $message;
            });

        $debug = $this->createNotifier('#opers', $notifier, translator: $translator);

        $debug->log(
            operator: 'Admin',
            command: 'SUSPEND',
            target: 'BadUser',
            reason: 'Spam',
            extra: ['duration' => '7d'],
        );

        self::assertStringContainsString('dur=7d', $capturedMessage);
    }

    #[Test]
    public function getDebugChannelReturnsConfiguredChannel(): void
    {
        $debug = $this->createNotifier('#debug');

        self::assertSame('#debug', $debug->getDebugChannel());
    }

    #[Test]
    public function getDebugChannelReturnsNullWhenNotConfigured(): void
    {
        $debug = $this->createNotifier(null);

        self::assertNull($debug->getDebugChannel());
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueForRoot(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(123);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($registeredNick);
        $operatorAccess = $this->createMock(NickServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('isIrcop')
            ->with('AdminRoot', 123, true, false)
            ->willReturn(true);
        $debug = $this->createNotifier(operatorAccess: $operatorAccess, nickRepo: $nickRepo);

        self::assertTrue($debug->isIrcopOrRoot('AdminRoot', true, false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseWhenNotIdentified(): void
    {
        $debug = $this->createNotifier();

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', false, false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseWhenNickNotRegistered(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $debug = $this->createNotifier(nickRepo: $nickRepo);

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', true, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueWhenUserIsIrcop(): void
    {
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getId')->willReturn(123);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())
            ->method('findByNick')
            ->with('OperUser')
            ->willReturn($registeredNick);

        $operatorAccess = $this->createMock(NickServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('isIrcop')
            ->with('OperUser', 123, true, true)
            ->willReturn(true);

        $debug = $this->createNotifier(nickRepo: $nickRepo, operatorAccess: $operatorAccess);

        self::assertTrue($debug->isIrcopOrRoot('OperUser', true, true));
    }

    #[Test]
    public function ensureChannelJoinedDoesNothing(): void
    {
        $debug = $this->createNotifier(null);

        $debug->ensureChannelJoined();

        self::assertNull($debug->getDebugChannel());
    }

    #[Test]
    public function realCatalogDebugOutputRemainsIdenticalInEveryLocale(): void
    {
        // Hashes pin the complete nine-message transcript before common extraction.
        $expectedHashes = [
            'ca' => 'a4b97bb38ad34f9bf0e661b9635a1bcf06fcc3587be22854dba771def03e8038',
            'de' => 'b0bcf9c373af50cf0a4e5ac3eaa58bc00ec091ceeaf10e254a3ff2d558c70e08',
            'el' => '92bed673a879fcb2f38419c136fa5064c332e2127beab5b6a7d40ef4020f52ac',
            'en' => '581287b83b6085d1fa028b334f1fac664481aa3f8123496b9628a92880af9c26',
            'es' => '66625d60acc0472c52af6ba5754169e1ad86bc7726a9e07d73320af148363931',
            'eu' => '6c17f7efc172c3940d57346f18a75787b6cad6a0bb2b3437ee2fc8005ab1ac8c',
            'fr' => '8017010cd9c565f3402a39ad53d961f09f67727d6ad73c36bd090d38ffdea67c',
            'gl' => 'e87bb52c2fb7f203462caca18f53ba6bed23362e0a9ec937c1bd4518abe95f80',
            'it' => '6ec349e0a7015de80b14de8565405c53ab551df96ee14de44a45fcab6f064e2c',
            'nl' => '3798426f5c947b7fa36a4d5491de0fd59d0997c4e781bd98eb455715a09e397c',
            'pl' => '0735b3d363c0e0c4a31597c2639407ad874312a9a53b176e2050f79beac3dc8a',
            'pt' => 'eb82ecfb3d8d3304dcf5a3404497e116a14dc693eacfc8c9cc36aa238bc876a2',
            'ro' => 'f86cb41c982fd12c5efc0ced9b4b7eb5cdd1e811784ec352176b71ffcbefd05b',
            'tr' => '28708dc0850f74bf113081debf9367806a604606e16db950d47018b869dbe0d5',
        ];
        foreach ($expectedHashes as $locale => $hash) {
            $translator = new Translator($locale);
            $translator->addLoader('yaml', new IrcYamlTranslationLoader());
            foreach (['common', 'nickserv'] as $domain) {
                $translator->addResource('yaml', __DIR__ . '/../../../../../translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
            }
            $messages = [];
            $transport = $this->createMock(NickServNotifierInterface::class);
            $transport->expects(self::exactly(9))->method('sendMessage')->willReturnCallback(
                static function (string $target, string $message, string $transport) use (&$messages): void {
                    $messages[] = [$target, $message, $transport];
                },
            );
            $debug = $this->createNotifier(notifier: $transport, translator: $translator, debugChannel: '#debug', language: $locale);
            $cases = [
                ['DROP', null, []],
                ['DROP', 'manual', []],
                ['SET', 'manual', ['option' => 'EMAIL', 'value' => 'new@example.test']],
                ['SET', null, ['option' => 'PASSWORD', 'value' => 'secretvalue']],
                ['SET', null, ['option' => 'EMAIL']],
                ['SUSPEND', 'manual', ['duration' => '1d']],
                ['DROP', null, ['founder_action' => true]],
                ['KILL', 'message', ['reasonType' => 'message']],
                ['GLOBAL', 'hello', ['type' => 'NOTICE', 'count' => 2]],
            ];
            foreach ($cases as [$command, $reason, $extra]) {
                $debug->log('Admin', $command, 'Target', null, null, $reason, $extra);
            }
            self::assertSame($hash, hash('sha256', serialize($messages)), $locale);
        }
    }
}
