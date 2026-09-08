<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\Out\Service\ChanServDebugNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

#[CoversClass(ChanServDebugNotifier::class)]
final class ChanServDebugNotifierTest extends TestCase
{
    #[Test]
    public function getServiceNameReturnsChanServ(): void
    {
        $notifier = $this->createNotifier();

        self::assertSame('chanserv', $notifier->getServiceName());
    }

    #[Test]
    public function isConfiguredReturnsTrueWhenChannelIsSet(): void
    {
        $notifier = $this->createNotifier(debugChannel: '#ircops');

        self::assertTrue($notifier->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsNull(): void
    {
        $notifier = $this->createNotifier(debugChannel: null);

        self::assertFalse($notifier->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsEmpty(): void
    {
        $notifier = $this->createNotifier(debugChannel: '');

        self::assertFalse($notifier->isConfigured());
    }

    #[Test]
    public function notifySendsRawMessageWhenConfigured(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')
            ->with('#ircops', 'raw debug message', 'NOTICE');

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            debugChannel: '#ircops',
        );

        $notifier->notify('raw debug message');
    }

    #[Test]
    public function notifyDoesNothingWhenNotConfigured(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::never())->method('sendMessage');

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            debugChannel: null,
        );

        $notifier->notify('raw debug message');
    }

    #[Test]
    public function logSendsToChannelWhenConfigured(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')->with('#ircops', 'formatted message', 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'formatted message');

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'DROP', '#test', null, null, 'manual');
    }

    #[Test]
    public function logWithFounderActionSendsFounderActionReasonToChannel(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')->with('#ircops', self::stringContains('Level-founder action'), 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = '', string $locale = ''): string => match ($id) {
            'debug.founder_action' => 'Level-founder action',
            'debug.prefix_reason' => 'Reason: ' . (isset($params['%reason%']) && is_string($params['%reason%']) ? $params['%reason%'] : ''),
            'debug.action_message' => (isset($params['%operator%']) && is_string($params['%operator%']) ? $params['%operator%'] : '') . ' ' . (isset($params['%command%']) && is_string($params['%command%']) ? $params['%command%'] : '') . ' ' . (isset($params['%target%']) && is_string($params['%target%']) ? $params['%target%'] : '') . ' ' . (isset($params['%reason%']) && is_string($params['%reason%']) ? $params['%reason%'] : ''),
            default => $id,
        });

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'SET', '#test', 'ident@host', '10.0.0.1', null, ['founder_action' => true]);
    }

    #[Test]
    public function logWithOptionAndValueSendsActionWithValueToChannel(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')
            ->with('#ircops', self::stringContains('URL=http://example.com'), 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = '', string $locale = ''): string => match ($id) {
            'debug.action_with_value' => (isset($params['%operator%']) && is_string($params['%operator%']) ? $params['%operator%'] : '') . ' SET ' . (isset($params['%target%']) && is_string($params['%target%']) ? $params['%target%'] : '') . ' Option: ' . (isset($params['%option%']) && is_string($params['%option%']) ? $params['%option%'] : '') . '=' . (isset($params['%value%']) && is_string($params['%value%']) ? $params['%value%'] : ''),
            default => $id,
        });

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'SET', '#test', 'ident@host', '10.0.0.1', null, ['founder_action' => true, 'option' => 'URL', 'value' => 'http://example.com']);
    }

    #[Test]
    public function logWithOptionOnlySendsActionWithOptionToChannel(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')
            ->with('#ircops', self::stringContains('SUSPEND'), 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = '', string $locale = ''): string => match ($id) {
            'debug.action_with_option' => (isset($params['%operator%']) && is_string($params['%operator%']) ? $params['%operator%'] : '') . ' SUSPEND ' . (isset($params['%target%']) && is_string($params['%target%']) ? $params['%target%'] : '') . ' Option: ' . (isset($params['%option%']) && is_string($params['%option%']) ? $params['%option%'] : ''),
            default => $id,
        });

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'SUSPEND', '#test', null, null, 'abuse', ['option' => 'SUSPEND']);
    }

    #[Test]
    public function logWithPasswordOptionHidesValueOnChannel(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')
            ->with('#ircops', self::logicalAnd(
                self::stringContains('PASSWORD'),
                self::logicalNot(self::stringContains('secretvalue')),
            ), 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = '', string $locale = ''): string => match ($id) {
            'debug.action_with_option' => (isset($params['%operator%']) && is_string($params['%operator%']) ? $params['%operator%'] : '') . ' SASET ' . (isset($params['%target%']) && is_string($params['%target%']) ? $params['%target%'] : '') . ' Option: ' . (isset($params['%option%']) && is_string($params['%option%']) ? $params['%option%'] : ''),
            default => $id,
        });

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'SASET', 'TargetUser', null, null, null, ['option' => 'PASSWORD', 'value' => 'secretvalue']);
    }

    #[Test]
    public function logWithReasonAndNoFounderActionUsesGivenReason(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')->with('#ircops', self::logicalAnd(
            self::stringContains('manual'),
            self::logicalNot(self::stringContains('Level-founder action')),
        ), 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = '', string $locale = ''): string => match ($id) {
            'debug.prefix_reason' => 'Reason: ' . (isset($params['%reason%']) && is_string($params['%reason%']) ? $params['%reason%'] : ''),
            'debug.action_message' => (isset($params['%operator%']) && is_string($params['%operator%']) ? $params['%operator%'] : '') . ' ' . (isset($params['%command%']) && is_string($params['%command%']) ? $params['%command%'] : '') . ' ' . (isset($params['%target%']) && is_string($params['%target%']) ? $params['%target%'] : '') . ' ' . (isset($params['%reason%']) && is_string($params['%reason%']) ? $params['%reason%'] : ''),
            default => $id,
        });

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'DROP', '#test', null, null, 'manual');
    }

    #[Test]
    public function ensureChannelJoinedDoesNothing(): void
    {
        $notifier = $this->createNotifier(debugChannel: '#ircops');

        $notifier->ensureChannelJoined();

        self::assertTrue($notifier->isConfigured());
    }

    #[Test]
    public function logToChannelReturnsWhenDebugChannelIsNull(): void
    {
        $notifier = $this->createNotifier(debugChannel: null);
        $method = new ReflectionMethod($notifier, 'logToChannel');

        $method->invoke($notifier, 'OperUser', 'DROP', '#test', null, null, null, []);

        self::assertFalse($notifier->isConfigured());
    }

    private function createNotifier(
        ?ChanServNotifierInterface $chanNotifier = null,
        ?TranslatorInterface $translator = null,
        ?string $debugChannel = null,
    ): ChanServDebugNotifier {
        return new ChanServDebugNotifier(
            $chanNotifier ?? $this->createStub(ChanServNotifierInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            'en',
            $debugChannel,
        );
    }
}
