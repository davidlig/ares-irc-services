<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Service;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\Out\Service\ChanServDebugNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Translation\Translator;
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
        string $language = 'en',
    ): ChanServDebugNotifier {
        return new ChanServDebugNotifier(
            $chanNotifier ?? $this->createStub(ChanServNotifierInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            $language,
            $debugChannel,
        );
    }

    #[Test]
    public function realCatalogDebugOutputRemainsIdenticalInEveryLocale(): void
    {
        // Hashes pin the complete nine-message transcript before common extraction.
        $expectedHashes = [
            'ca' => 'bf0007be57906d752a3e090b206bc61ffd321a3c694f0b5b12966923cd4a597f',
            'de' => 'cd06ea328cb1844400e4d0d33b1dec3f74ebf2fe0c0b9d3785c3f9ae0aaa14e2',
            'el' => 'ea891064b73d5d27ef421b05f1973c8afc2fc6a593b2c7d6ec18e41292eb452f',
            'en' => 'aa32219d809c22e3a343ed6f762508107d9bf5b7000c3398a75c1f5bc14ac6fd',
            'es' => 'c88cb85e7136b51b8175a5af49259c903feb88320c9fe01a3aa2d2fdb5d37c8c',
            'eu' => '520e73a2039d1bd637e68f73790b03a44b80849cc5f39c97137a8cfef9f41507',
            'fr' => '1ee29ea786a77faa112b0a20556eda2fcf32e7b972a0736c551cc2880cb5e33f',
            'gl' => 'c74381ad71d0f8bf8adcd0b222e2b389e17ce1541f301d77ed93ddb03b9defa3',
            'it' => 'c2344c26d61268e3b3f1fa3d1e1c560d14ee06f878e874ea729e049ebc050afe',
            'nl' => 'b14630a373ec49af6fd4b970348246651be69ba91f11c918f9f029a3dda72514',
            'pl' => '418f8afb042bdf1647b22adaa4f4f5025f4d94ab5f2856cbe850d7ab21ae3964',
            'pt' => '2bf6ce5fa95c563cf2b796d85751a423e50682a88b296dd419a45d5f2a5c7f7b',
            'ro' => '7796f94373e9993d707c41a6538dfe2b04c95a02b85a8e0a9dd68ea2faab8cc0',
            'tr' => '19b840cb84c25e1499dffe728420bec40bd2fbadaff561eb2b8747ba08504a19',
        ];
        foreach ($expectedHashes as $locale => $hash) {
            $translator = new Translator($locale);
            $translator->addLoader('yaml', new IrcYamlTranslationLoader());
            foreach (['common', 'chanserv'] as $domain) {
                $translator->addResource('yaml', __DIR__ . '/../../../../../translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
            }
            $messages = [];
            $transport = $this->createMock(ChanServNotifierInterface::class);
            $transport->expects(self::exactly(9))->method('sendMessage')->willReturnCallback(
                static function (string $target, string $message, string $transport) use (&$messages): void {
                    $messages[] = [$target, $message, $transport];
                },
            );
            $debug = $this->createNotifier(chanNotifier: $transport, translator: $translator, debugChannel: '#debug', language: $locale);
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
