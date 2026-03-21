<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServContext::class)]
final class MemoServContextTest extends TestCase
{
    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $chanservProvider = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $memoservProvider = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $operservProvider = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([
            $nickservProvider,
            $chanservProvider,
            $memoservProvider,
            $operservProvider,
        ]);
    }

    #[Test]
    public function replyTranslatesAndSendsWhenSenderIsSet(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg, string $type) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg, 'type' => $type];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params, string $domain, ?string $locale): string => $id . '|' . ($params['%name%'] ?? ''));
        $registry = new MemoServCommandRegistry([]);
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip');
        $context = new MemoServContext(
            $sender,
            null,
            'SEND',
            [],
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->reply('memo.sent', ['name' => 'Bob']);

        self::assertCount(1, $sent);
        self::assertSame('UID1', $sent[0]['uid']);
        self::assertSame('memo.sent|Bob', $sent[0]['msg']);
        self::assertSame('NOTICE', $sent[0]['type']);
    }

    #[Test]
    public function replyDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function () use (&$sent): void {
            $sent[] = true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            null,
            null,
            'SEND',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->reply('some.key');

        self::assertCount(0, $sent);
    }

    #[Test]
    public function replyRawSendsMessageWhenSenderIsSet(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $sender = new SenderView('UID2', 'User', 'i', 'h', 'c', 'ip');
        $context = new MemoServContext(
            $sender,
            null,
            'READ',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->replyRaw('Raw line');

        self::assertCount(1, $sent);
        self::assertSame('UID2', $sent[0]['uid']);
        self::assertSame('Raw line', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function () use (&$sent): void {
            $sent[] = true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            null,
            null,
            'LIST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->replyRaw('Anything');

        self::assertCount(0, $sent);
    }

    #[Test]
    public function gettersReturnInjectedValues(): void
    {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            ['arg1'],
            $notifier,
            $translator,
            'es',
            'Europe/Madrid',
            'PRIVMSG',
            $registry,
            $this->createServiceNicks(),
        );

        self::assertSame($notifier, $context->getNotifier());
        self::assertSame('es', $context->getLanguage());
        self::assertSame('Europe/Madrid', $context->getTimezone());
        self::assertSame($registry, $context->getRegistry());
        self::assertSame('HELP', $context->command);
        self::assertSame(['arg1'], $context->args);
    }

    #[Test]
    public function formatDateReturnsDashWhenDateIsNull(): void
    {
        $context = $this->createMinimalContext();

        self::assertSame('—', $context->formatDate(null));
    }

    #[Test]
    public function formatDateFormatsWithContextTimezone(): void
    {
        $context = $this->createMinimalContext('UTC');
        $date = new DateTimeImmutable('2025-03-14 12:00:00', new DateTimeZone('UTC'));

        $formatted = $context->formatDate($date);

        self::assertSame('14/03/2025 12:00 UTC', $formatted);
    }

    #[Test]
    public function transReturnsTranslationWithWrappedParams(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params): string => $id . ':' . ($params['%nick%'] ?? ''));
        $context = new MemoServContext(
            null,
            null,
            'SEND',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );

        $result = $context->trans('memo.from', ['nick' => 'Alice']);

        self::assertSame('memo.from:Alice', $result);
    }

    private function createMinimalContext(string $timezone = 'UTC'): MemoServContext
    {
        return new MemoServContext(
            null,
            null,
            'LIST',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            $timezone,
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }
}
