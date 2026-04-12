<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Service;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Infrastructure\ChanServ\Service\ChanServDebugNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;

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
    public function logWritesToFileAndDoesNotSendToChannelWhenNotConfigured(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::never())->method('sendMessage');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('DROP', self::callback(static fn (array $context): bool => 'OperUser' === $context['operator']
            && 'DROP' === $context['command']
            && '#test' === $context['target']
            && 'manual' === $context['reason']));

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            logger: $logger,
            debugChannel: null,
        );

        $notifier->log('OperUser', 'DROP', '#test', null, null, 'manual');
    }

    #[Test]
    public function logWritesToFileAndSendsToChannelWhenConfigured(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')->with('#ircops', 'formatted message', 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'formatted message');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            logger: $logger,
            debugChannel: '#ircops',
        );

        $notifier->log('OperUser', 'DROP', '#test', null, null, 'manual');
    }

    #[Test]
    public function logWithoutReasonDoesNotIncludeReasonInFileContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('DROP', self::callback(static fn (array $context): bool => !array_key_exists('reason', $context)));

        $notifier = $this->createNotifier(logger: $logger, debugChannel: null);

        $notifier->log('OperUser', 'DROP', '#test');
    }

    #[Test]
    public function logWithReasonIncludesReasonInFileContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('DROP', self::callback(static fn (array $context): bool => 'manual' === $context['reason']));

        $notifier = $this->createNotifier(logger: $logger, debugChannel: null);

        $notifier->log('OperUser', 'DROP', '#test', null, null, 'manual');
    }

    #[Test]
    public function logWithExtraIncludesExtraInFileContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('DROP', self::callback(static fn (array $context): bool => ['was_online' => true] === $context['extra']));

        $notifier = $this->createNotifier(logger: $logger, debugChannel: null);

        $notifier->log('OperUser', 'DROP', '#test', null, null, null, ['was_online' => true]);
    }

    #[Test]
    public function logWithTargetHostIncludesTargetHostInFileContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('DROP', self::callback(static fn (array $context): bool => 'user@host' === $context['target_host']));

        $notifier = $this->createNotifier(logger: $logger, debugChannel: null);

        $notifier->log('OperUser', 'DROP', '#test', 'user@host', null, 'manual');
    }

    #[Test]
    public function logWithTargetIpIncludesTargetIpInFileContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('DROP', self::callback(static fn (array $context): bool => '10.0.0.1' === $context['target_ip']));

        $notifier = $this->createNotifier(logger: $logger, debugChannel: null);

        $notifier->log('OperUser', 'DROP', '#test', null, '10.0.0.1', 'manual');
    }

    #[Test]
    public function logWithFounderActionSendsFounderActionReasonToChannel(): void
    {
        $chanNotifier = $this->createMock(ChanServNotifierInterface::class);
        $chanNotifier->expects(self::once())->method('sendMessage')->with('#ircops', self::stringContains('Level-founder action'), 'NOTICE');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], string $domain = '', string $locale = ''): string => match ($id) {
            'debug.founder_action' => 'Level-founder action',
            'debug.prefix_reason' => 'Reason: ' . ($params['%reason%'] ?? ''),
            'debug.action_message' => ($params['%operator%'] ?? '') . ' ' . ($params['%command%'] ?? '') . ' ' . ($params['%target%'] ?? '') . ' ' . ($params['%reason%'] ?? ''),
            default => $id,
        });

        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            logger: $logger,
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
            'debug.action_with_value' => ($params['%operator%'] ?? '') . ' SET ' . ($params['%target%'] ?? '') . ' Option: ' . ($params['%option%'] ?? '') . '=' . ($params['%value%'] ?? ''),
            default => $id,
        });

        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            logger: $logger,
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
            'debug.action_with_option' => ($params['%operator%'] ?? '') . ' SUSPEND ' . ($params['%target%'] ?? '') . ' Option: ' . ($params['%option%'] ?? ''),
            default => $id,
        });

        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            logger: $logger,
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
            'debug.action_with_option' => ($params['%operator%'] ?? '') . ' SASET ' . ($params['%target%'] ?? '') . ' Option: ' . ($params['%option%'] ?? ''),
            default => $id,
        });

        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            logger: $logger,
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
            'debug.prefix_reason' => 'Reason: ' . ($params['%reason%'] ?? ''),
            'debug.action_message' => ($params['%operator%'] ?? '') . ' ' . ($params['%command%'] ?? '') . ' ' . ($params['%target%'] ?? '') . ' ' . ($params['%reason%'] ?? ''),
            default => $id,
        });

        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createNotifier(
            chanNotifier: $chanNotifier,
            translator: $translator,
            logger: $logger,
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

    private function createNotifier(
        ?ChanServNotifierInterface $chanNotifier = null,
        ?TranslatorInterface $translator = null,
        ?string $debugChannel = null,
        ?LoggerInterface $logger = null,
    ): ChanServDebugNotifier {
        return new ChanServDebugNotifier(
            $chanNotifier ?? $this->createStub(ChanServNotifierInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            'en',
            $debugChannel,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
