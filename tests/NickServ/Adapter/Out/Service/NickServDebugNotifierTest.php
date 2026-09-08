<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Service;

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
    ): NickServDebugNotifier {
        return new NickServDebugNotifier(
            $notifier ?? $this->createStub(NickServNotifierInterface::class),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $identifiedRegistry ?? new IdentifiedSessionRegistry(),
            $operatorAccess ?? $this->createStub(NickServOperatorAccess::class),
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            'en',
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
        $operatorAccess = $this->createStub(NickServOperatorAccess::class);
        $operatorAccess->method('isRoot')->willReturn(true);
        $debug = $this->createNotifier(operatorAccess: $operatorAccess);

        self::assertTrue($debug->isIrcopOrRoot('AdminRoot', true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseWhenNotIdentified(): void
    {
        $debug = $this->createNotifier();

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseWhenNickNotRegistered(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $debug = $this->createNotifier(nickRepo: $nickRepo);

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', true));
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
        $operatorAccess->expects(self::once())->method('isRoot')->with('OperUser')->willReturn(false);
        $operatorAccess->expects(self::once())->method('isIrcop')->with(123, 'OperUser')->willReturn(true);

        $debug = $this->createNotifier(nickRepo: $nickRepo, operatorAccess: $operatorAccess);

        self::assertTrue($debug->isIrcopOrRoot('OperUser', true));
    }

    #[Test]
    public function ensureChannelJoinedDoesNothing(): void
    {
        $debug = $this->createNotifier(null);

        $debug->ensureChannelJoined();

        self::assertNull($debug->getDebugChannel());
    }
}
