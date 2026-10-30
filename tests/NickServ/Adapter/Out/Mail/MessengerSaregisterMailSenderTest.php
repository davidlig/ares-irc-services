<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Mail;

use App\NickServ\Adapter\Out\Mail\MessengerSaregisterMailSender;
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

#[CoversClass(MessengerSaregisterMailSender::class)]
final class MessengerSaregisterMailSenderTest extends TestCase
{
    #[Test]
    public function translatesAndDispatchesTheRescueTokenMail(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnMap([
            ['saregister_token_subject', ['%bot%' => 'ConfiguredServ'], 'mail', 'es', 'subject'],
            ['saregister_token_body', ['%nickname%' => 'Nick', '%token%' => 'safe-token', '%bot%' => 'ConfiguredServ'], 'mail', 'es', 'body'],
        ]);
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof RegistrationVerificationEmail
                && 'user@example.com' === $message->to
                && 'subject' === $message->subject
                && 'body' === $message->body,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new MessengerSaregisterMailSender(
            $dispatcher,
            $translator,
            $this->createStub(LoggerInterface::class),
            'ConfiguredServ',
        )->sendRescueToken('Nick', 'user@example.com', 'safe-token', 'es');
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
            'NickServ SAREGISTER: failed to dispatch rescue token email',
            self::callback(static function (array $context): bool {
                $serialized = serialize($context);

                return 'Nick' === $context['nick']
                    && 'user@example.com' === $context['recipient']
                    && RuntimeException::class === $context['exception_class']
                    && !str_contains($serialized, 'secret-token');
            }),
        );

        $sender = new MessengerSaregisterMailSender($dispatcher, $translator, $logger, 'NickServ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');
        $sender->sendRescueToken('Nick', 'user@example.com', 'secret-token', 'en');
    }
}
