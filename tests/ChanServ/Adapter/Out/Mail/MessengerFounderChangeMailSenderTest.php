<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Mail;

use App\ChanServ\Adapter\Out\Mail\FounderChangeEmail;
use App\ChanServ\Adapter\Out\Mail\MessengerFounderChangeMailSender;
use App\Shared\Application\Port\TranslationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(MessengerFounderChangeMailSender::class)]
final class MessengerFounderChangeMailSenderTest extends TestCase
{
    #[Test]
    public function translatesAndDispatchesTheSemanticFounderChangeMail(): void
    {
        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnMap([
            ['founder_change_token_subject', ['%channel%' => '#channel'], 'mail', 'es', 'subject'],
            ['founder_change_token_body', [
                '%channel%' => '#channel',
                '%new_nick%' => 'NewNick',
                '%token%' => 'safe-token',
                '%command%' => 'SET #channel FOUNDER NewNick safe-token',
                '%bot%' => 'ChanServ',
            ], 'mail', 'es', 'body'],
        ]);
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof FounderChangeEmail
                && 'user@example.com' === $message->to
                && 'subject' === $message->subject
                && 'body' === $message->body,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new MessengerFounderChangeMailSender(
            $dispatcher,
            $translator,
            $this->createStub(LoggerInterface::class),
        )->sendFounderChangeToken('user@example.com', '#channel', 'NewNick', 'safe-token', 'ChanServ', 'es');
    }

    #[Test]
    public function logsOnlySafeMetadataAndRethrowsDeliveryFailure(): void
    {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturn('translated');
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willThrowException(new RuntimeException('transport failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'ChanServ SET FOUNDER: failed to send email',
            self::callback(static fn (array $context): bool => '#channel' === $context['channel']
                && 'user@example.com' === $context['recipient']
                && RuntimeException::class === $context['exception_class']),
        );

        $sender = new MessengerFounderChangeMailSender(
            $dispatcher,
            $translator,
            $logger,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');

        $sender->sendFounderChangeToken('user@example.com', '#channel', 'NewNick', 'safe-token', 'ChanServ', 'es');
    }
}
