<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Mail;

use App\NickServ\Adapter\Out\Mail\MessengerEmailChangeMailSender;
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

#[CoversClass(MessengerEmailChangeMailSender::class)]
final class MessengerEmailChangeMailSenderTest extends TestCase
{
    #[Test]
    public function translatesAndDispatchesEmailChangeVerification(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnMap([
            ['email_change_token_subject', ['%bot%' => 'ConfiguredServ'], 'mail', 'es', 'subject'],
            ['email_change_token_body', ['%new_email%' => 'new@example.com', '%token%' => 'safe-token', '%bot%' => 'ConfiguredServ'], 'mail', 'es', 'body'],
        ]);
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof RegistrationVerificationEmail
                && 'current@example.com' === $message->to
                && 'subject' === $message->subject
                && 'body' === $message->body,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new MessengerEmailChangeMailSender(
            $dispatcher,
            $translator,
            $this->createStub(LoggerInterface::class),
            'ConfiguredServ',
        )->sendVerification('current@example.com', 'Nick', 'new@example.com', 'safe-token', 'es');
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
            'NickServ SET EMAIL: failed to dispatch token email',
            self::callback(static function (array $context): bool {
                $serialized = serialize($context);

                return 'Nick' === $context['nick']
                    && 'current@example.com' === $context['recipient']
                    && RuntimeException::class === $context['exception_class']
                    && !str_contains($serialized, 'safe-token')
                    && !str_contains($serialized, 'new@example.com');
            }),
        );

        $sender = new MessengerEmailChangeMailSender($dispatcher, $translator, $logger, 'NickServ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');
        $sender->sendVerification('current@example.com', 'Nick', 'new@example.com', 'safe-token', 'en');
    }
}
