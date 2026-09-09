<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Mail;

use App\NickServ\Adapter\Out\Mail\MessengerResendMailSender;
use App\NickServ\Adapter\Out\Mail\RegistrationVerificationEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function serialize;
use function str_contains;

#[CoversClass(MessengerResendMailSender::class)]
final class MessengerResendMailSenderTest extends TestCase
{
    #[Test]
    public function translatesAndDispatchesTheSemanticResendMail(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnMap([
            ['resend_verification_subject', ['%bot%' => 'ConfiguredServ'], 'mail', 'es', 'subject'],
            ['resend_verification_body', ['%nickname%' => 'Nick', '%token%' => 'safe-token', '%bot%' => 'ConfiguredServ'], 'mail', 'es', 'body'],
        ]);
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof RegistrationVerificationEmail
                && 'user@example.com' === $message->to
                && 'subject' === $message->subject
                && 'body' === $message->body,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new MessengerResendMailSender(
            $dispatcher,
            $translator,
            $this->createStub(LoggerInterface::class),
            'ConfiguredServ',
        )->sendResend('Nick', 'user@example.com', 'safe-token', 'es');
    }

    #[Test]
    public function logsOnlySafeMetadataAndRethrowsDeliveryFailure(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willThrowException(new RuntimeException('transport failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'NickServ RESEND: failed to dispatch verification email',
            self::callback(static function (array $context): bool {
                $serialized = serialize($context);

                return 'Nick' === $context['nick']
                    && 'user@example.com' === $context['recipient']
                    && RuntimeException::class === $context['exception_class']
                    && !str_contains($serialized, 'secret-token');
            }),
        );

        $sender = new MessengerResendMailSender($dispatcher, $translator, $logger, 'NickServ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');
        $sender->sendResend('Nick', 'user@example.com', 'secret-token', 'en');
    }
}
