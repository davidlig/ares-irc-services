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
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_shift;
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

    /** @return iterable<string, array{string,string,string,string}> */
    public static function clientIdentities(): iterable
    {
        yield 'raw IP' => ['raw-ip', 'cloak', 'host', 'ip:raw-ip'];
        yield 'cloak' => ['*', 'cloak', 'host', 'cloak:cloak'];
        yield 'host' => ['', '', 'host', 'host:host'];
        yield 'UID' => ['', '', '', 'uid:UID1'];
    }

    #[Test]
    #[DataProvider('clientIdentities')]
    public function sharesStableRescueBudgetIdentity(string $ip, string $cloak, string $host, string $expected): void
    {
        $application = $this->createMock(TransferChannelFounderHandlerInterface::class);
        $application->expects(self::once())->method('handle')->with(self::callback(static fn (TransferChannelFounder $input): bool => $expected === $input->actor?->clientKey))->willReturn(new TransferChannelFounderResult(TransferFounderOutcome::Ignored));
        new SetFounderHandler($application)->handle($this->context($this->notifier(), ['#test', 'FOUNDER', 'Target', 'token'], $ip, hostname: $host, cloakedHost: $cloak), $this->createStub(RegisteredChannel::class), 'Target');
    }

    /** @return iterable<string, array{TransferChannelFounderResult, ?string}> */
    public static function replyOutcomes(): iterable
    {
        yield 'missing target' => [new TransferChannelFounderResult(TransferFounderOutcome::MissingTarget), 'set.founder.syntax'];
        yield 'unknown target' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetNotFound, 'Nobody'), "\x0304✗\x03\x0F presentation.error.nick_not_registered"];
        yield 'suspended target' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetSuspended, 'Target'), "\x0304✗\x03\x0F set.founder.suspended"];
        yield 'unregistered target' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetNotRegistered, 'Target'), "\x0304✗\x03\x0F set.founder.must_be_registered"];
        yield 'same founder' => [new TransferChannelFounderResult(TransferFounderOutcome::SameFounder), "\x0304✗\x03\x0F set.founder.cannot_be_self"];
        yield 'successor' => [new TransferChannelFounderResult(TransferFounderOutcome::TargetIsSuccessor), "\x0304✗\x03\x0F set.founder.cannot_be_successor"];
        yield 'limit' => [new TransferChannelFounderResult(TransferFounderOutcome::ChannelLimitReached, 'Target', maximumChannelsPerNick: 3), "\x0304✗\x03\x0F set.founder.limit_exceeded"];
        yield 'missing token' => [new TransferChannelFounderResult(TransferFounderOutcome::TokenRequired), "\x0304✗\x03\x0F set.founder.token_required"];
        yield 'unavailable' => [new TransferChannelFounderResult(TransferFounderOutcome::CredentialUnavailable), "\x0304✗\x03\x0F set.founder.token_unavailable"];
        yield 'throttled' => [new TransferChannelFounderResult(TransferFounderOutcome::Throttled), "\x0304✗\x03\x0F set.founder.throttled"];
        yield 'invalid token' => [new TransferChannelFounderResult(TransferFounderOutcome::InvalidToken), "\x0304✗\x03\x0F set.founder.invalid_token"];
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
            new TransferChannelFounderResult(TransferFounderOutcome::TokenRequired),
            new TransferChannelFounderResult(TransferFounderOutcome::Throttled, retryAfterSeconds: 61),
        ];
        $application = $this->createStub(TransferChannelFounderHandlerInterface::class);
        $application->method('handle')->willReturnCallback(static function () use (&$results): TransferChannelFounderResult {
            return array_shift($results) ?? throw new LogicException('Missing founder transfer result.');
        });
        $messages = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'set.founder.limit_exceeded' => '%nickname% %max%',
            'set.founder.throttled' => '%minutes%',
            default => $key,
        });
        $notifier = $this->notifier($messages);
        $handler = new SetFounderHandler($application);
        $context = $this->context($notifier, ['#test', 'FOUNDER', 'Target'], translator: $translator);

        $handler->handle($context, $this->createStub(RegisteredChannel::class), 'Target');
        $handler->handle($context, $this->createStub(RegisteredChannel::class), 'Target');

        $handler->handle($context, $this->createStub(RegisteredChannel::class), 'Target');

        self::assertSame([
            "\x0304✗\x03\x0F \x02Busy\x03\x0F 7",
            "\x0304✗\x03\x0F set.founder.token_required",
            "\x0304✗\x03\x0F 2",
        ], $messages);
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

        self::assertSame(["\x0303✔\x03\x0F set.founder.updated"], $messages);
        self::assertSame([['#test', "\x0310ℹ\x03\x0F set.founder.notice_channel"]], $notices);
    }

    /**
     * @param list<string> $args
     */
    private function context(
        ChanServNotifierInterface $notifier,
        array $args,
        string $ipBase64 = 'raw-ip',
        bool $founderEquivalent = false,
        ?TranslatorInterface $translator = null,
        bool $withoutSender = false,
        string $hostname = 'host.test',
        string $cloakedHost = 'cloak',
    ): ChanServContext {
        return new ChanServContext(
            $withoutSender ? null : new SenderView('UID1', 'Founder', 'ident', $hostname, $cloakedHost, $ipBase64),
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

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
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
