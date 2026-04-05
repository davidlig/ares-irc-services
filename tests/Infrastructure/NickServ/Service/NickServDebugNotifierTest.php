<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\NickServ\Service\NickServDebugNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NickServDebugNotifier::class)]
final class NickServDebugNotifierTest extends TestCase
{
    private function createNotifier(
        ?string $debugChannel = '#opers',
        ?NickServNotifierInterface $notifier = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?RootUserRegistry $rootRegistry = null,
        ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
    ): NickServDebugNotifier {
        return new NickServDebugNotifier(
            $notifier ?? $this->createStub(NickServNotifierInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new IdentifiedSessionRegistry(),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $rootRegistry ?? new RootUserRegistry(''),
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            'en',
            $debugChannel,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    #[Test]
    public function getServiceNameReturnsNickserv(): void
    {
        $notifier = $this->createNotifier();

        self::assertSame('nickserv', $notifier->getServiceName());
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
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale) use (&$capturedMessage): string {
                if ('debug.action_with_option' === $id) {
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
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale) use (&$capturedMessage): string {
                if ('debug.action_with_value' === $id) {
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
    public function logWithDuration(): void
    {
        $capturedMessage = '';

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params, string $domain, string $locale) use (&$capturedMessage): string {
                if ('debug.action_duration' === $id) {
                    return 'dur=' . $params['%duration%'];
                }
                if ('debug.prefix_reason' === $id) {
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
        $rootRegistry = new RootUserRegistry('AdminRoot');
        $debug = $this->createNotifier(rootRegistry: $rootRegistry);

        self::assertTrue($debug->isIrcopOrRoot('AdminRoot', false));
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
        $ircop = $this->createStub(OperIrcop::class);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())
            ->method('findByNick')
            ->with('OperUser')
            ->willReturn($registeredNick);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())
            ->method('findByNickId')
            ->with(123)
            ->willReturn($ircop);

        $debug = $this->createNotifier(nickRepo: $nickRepo, ircopRepo: $ircopRepo);

        self::assertTrue($debug->isIrcopOrRoot('OperUser', true));
    }

    #[Test]
    public function ensureChannelJoinedDoesNothing(): void
    {
        $debug = $this->createNotifier();

        $debug->ensureChannelJoined();

        self::assertTrue(true);
    }

    #[Test]
    public function logIncludesAllContextInFileLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('SASET', self::callback(static function (array $context): bool {
                self::assertSame('Admin', $context['operator']);
                self::assertSame('SASET', $context['command']);
                self::assertSame('TargetUser', $context['target']);
                self::assertSame('user@host.com', $context['target_host']);
                self::assertSame('10.0.0.1', $context['target_ip']);
                self::assertSame('Test reason', $context['reason']);
                self::assertSame(['option' => 'EMAIL'], $context['extra']);

                return true;
            }));

        $debug = $this->createNotifier(null, logger: $logger);

        $debug->log(
            operator: 'Admin',
            command: 'SASET',
            target: 'TargetUser',
            targetHost: 'user@host.com',
            targetIp: '10.0.0.1',
            reason: 'Test reason',
            extra: ['option' => 'EMAIL'],
        );
    }
}
