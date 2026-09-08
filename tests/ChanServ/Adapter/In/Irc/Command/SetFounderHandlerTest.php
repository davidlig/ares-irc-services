<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetFounderHandler;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounder;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderHandlerInterface;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderResult;
use App\ChanServ\Application\UseCase\TransferFounder\TransferFounderOutcome;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;

#[CoversClass(SetFounderHandler::class)]
final class SetFounderHandlerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function ipRepresentations(): iterable
    {
        yield 'IPv4' => [base64_encode("\x7f\x00\x00\x01"), '127.0.0.1'];
        yield 'wildcard' => ['*', '*'];
        yield 'empty' => ['', '*'];
        yield 'invalid base64' => ['raw-ip', 'raw-ip'];
        yield 'invalid binary address' => [base64_encode('x'), base64_encode('x')];
    }

    #[Test]
    #[DataProvider('ipRepresentations')]
    public function mapsIrcContextToTypedUseCaseInput(string $wireIp, string $expectedIp): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $application = $this->createMock(TransferChannelFounderHandlerInterface::class);
        $application->expects(self::once())->method('handle')->with(self::callback(
            static fn (TransferChannelFounder $command): bool => $channel === $command->channel
                && 'Target' === $command->targetNickname
                && 'safe-token' === $command->token
                && null !== $command->actor
                && 'Founder' === $command->actor->nickname
                && 10 === $command->actor->registeredNickId
                && $expectedIp === $command->actor->ipAddress
                && 'ident@host.test' === $command->actor->hostmask
                && $command->founderEquivalent
                && 'ConfiguredChanServ' === $command->serviceNickname
                && 'es' === $command->locale,
        ))->willReturn(new TransferChannelFounderResult(TransferFounderOutcome::Ignored));

        $notifier = $this->notifier();
        new SetFounderHandler($application)->handle(
            $this->context($notifier, ['#test', 'FOUNDER', 'Target', ' safe-token '], $wireIp, true),
            $channel,
            ' Target ',
        );
    }

    #[Test]
    public function missingSenderMapsToNullActorAndMissingTokenRemainsNull(): void
    {
        $application = $this->createMock(TransferChannelFounderHandlerInterface::class);
        $application->expects(self::once())->method('handle')->with(self::callback(
            static fn (TransferChannelFounder $command): bool => null === $command->actor && null === $command->token,
        ))->willReturn(new TransferChannelFounderResult(TransferFounderOutcome::Ignored));

        new SetFounderHandler($application)->handle(
            $this->context($this->notifier(), ['#test', 'FOUNDER', 'Target'], withoutSender: true),
            $this->createStub(RegisteredChannel::class),
            'Target',
        );
    }

    /** @return iterable<string, array{TransferChannelFounderResult, ?string}> */
    public static function replyOutcomes(): iterable
    {
        yield 'missing target' => [new TransferChannelFounderResult(TransferFounderOutcome::MissingTarget), 'set.founder.syntax'];
        yield 'unknown target' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetNotFound, 'Nobody'), 'error.nick_not_registered'];
        yield 'suspended target' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetSuspended, 'Target'), 'set.founder.suspended'];
        yield 'unregistered target' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetNotRegistered, 'Target'), 'set.founder.must_be_registered'];
        yield 'same founder' => [new TransferChannelFounderResult(TransferFounderOutcome::SameFounder), 'set.founder.cannot_be_self'];
        yield 'successor' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetIsSuccessor), 'set.founder.cannot_be_successor'];
        yield 'limit' => [new TransferChannelFounderResult(TransferFounderOutcome::ChannelLimitReached, 'Target', maximumChannelsPerNick: 3), 'set.founder.limit_exceeded'];
        yield 'no email' => [new TransferChannelFounderResult(TransferFounderOutcome::CurrentFounderWithoutEmail), 'set.founder.no_email'];
        yield 'throttled' => [new TransferChannelFounderResult(TransferFounderOutcome::Throttled), 'set.founder.throttled'];
        yield 'sent' => [new TransferChannelFounderResult(TransferFounderOutcome::TokenSent, emailHint: 'fo***@example.com'), 'set.founder.token_sent'];
        yield 'invalid token' => [new TransferChannelFounderResult(TransferFounderOutcome::InvalidToken), 'set.founder.invalid_token'];
        yield 'mail failure' => [new TransferChannelFounderResult(TransferFounderOutcome::MailDeliveryFailed), 'error.mail_failed'];
        yield 'ignored' => [new TransferChannelFounderResult(TransferFounderOutcome::Ignored), null];
    }

    #[Test]
    #[DataProvider('replyOutcomes')]
    public function presentsSemanticReplyOutcomes(TransferChannelFounderResult $result, ?string $expectedKey): void
    {
        $application = $this->createStub(TransferChannelFounderHandlerInterface::class);
        $application->method('handle')->willReturn($result);
        $messages = [];
        $notifier = $this->notifier($messages);
        $translator = $this->translator();

        new SetFounderHandler($application)->handle(
            $this->context($notifier, ['#test', 'FOUNDER', 'Target'], translator: $translator),
            $this->createStub(RegisteredChannel::class),
            'Target',
        );

        self::assertSame(null === $expectedKey ? [] : [$expectedKey], $messages);
    }

    #[Test]
    public function limitAndTokenSentPresentationPreservePlaceholders(): void
    {
        $results = [
            new TransferChannelFounderResult(TransferFounderOutcome::ChannelLimitReached, 'Busy', maximumChannelsPerNick: 7),
            new TransferChannelFounderResult(TransferFounderOutcome::TokenSent, emailHint: 'fo***@example.com'),
        ];
        $application = $this->createStub(TransferChannelFounderHandlerInterface::class);
        $application->method('handle')->willReturnCallback(static function () use (&$results): TransferChannelFounderResult {
            return array_shift($results) ?? throw new LogicException('Missing founder transfer result.');
        });
        $translated = [];
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$translated): string {
            $translated[$key] = $params;

            return $key;
        });
        $notifier = $this->notifier();
        $handler = new SetFounderHandler($application);
        $context = $this->context($notifier, ['#test', 'FOUNDER', 'Target'], translator: $translator);

        $handler->handle($context, $this->createStub(RegisteredChannel::class), 'Target');
        $handler->handle($context, $this->createStub(RegisteredChannel::class), 'Target');

        self::assertSame('Busy', $translated['set.founder.limit_exceeded']['%nickname%']);
        self::assertSame('7', $translated['set.founder.limit_exceeded']['%max%']);
        self::assertSame('fo***@example.com', $translated['set.founder.token_sent']['%email_hint%']);
    }

    #[Test]
    public function updatedResultRepliesAndSendsTranslatedChannelNotice(): void
    {
        $application = $this->createStub(TransferChannelFounderHandlerInterface::class);
        $application->method('handle')->willReturn(new TransferChannelFounderResult(TransferFounderOutcome::Updated, 'NewFounder'));
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $messages = [];
        $notices = [];
        $notifier = $this->notifier($messages, $notices);

        new SetFounderHandler($application)->handle(
            $this->context($notifier, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.updated'], $messages);
        self::assertSame([['#test', 'set.founder.notice_channel']], $notices);
    }

    /**
     * @param list<string> $args
     */
    private function context(
        ChanServNotifierInterface $notifier,
        array $args,
        string $ipBase64 = 'raw-ip',
        bool $founderEquivalent = false,
        ?TranslationInterface $translator = null,
        bool $withoutSender = false,
    ): ChanServContext {
        return new ChanServContext(
            $withoutSender ? null : new SenderView('UID1', 'Founder', 'ident', 'host.test', 'cloak', $ipBase64),
            new ChanAccountView(10, 'Founder', 'es'),
            'SET',
            $args,
            $notifier,
            $translator ?? $this->translator(),
            'es',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->serviceNicks(),
            $founderEquivalent,
        );
    }

    /**
     * @param list<string>                $messages
     * @param list<array{string, string}> $notices
     */
    private function notifier(array &$messages = [], array &$notices = []): ChanServNotifierInterface
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ConfiguredChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $channel, string $message) use (&$notices): void {
            $notices[] = [$channel, $message];
        });

        return $notifier;
    }

    private function translator(): TranslationInterface
    {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return $translator;
    }

    private function serviceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'chanserv';
            }

            public function getNickname(): string
            {
                return 'ConfiguredChanServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }
}
