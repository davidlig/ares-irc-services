<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Mail;

use App\NickServ\Adapter\Out\Mail\MessengerRecoveryMailSender;
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

#[CoversClass(MessengerRecoveryMailSender::class)]
final class MessengerRecoveryMailSenderTest extends TestCase
{
    #[Test]
    public function translatesAndDispatchesTheRecoveryMail(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnMap([
            ['recovery_token_subject', ['%bot%' => 'ConfiguredServ'], 'mail', 'es', 'subject'],
            ['recovery_token_body', ['%nickname%' => 'Nick', '%token%' => 'safe-token', '%bot%' => 'ConfiguredServ'], 'mail', 'es', 'body'],
        ]);
        $dispatcher = $this->createMock(MessageBusInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof RegistrationVerificationEmail
                && 'user@example.com' === $message->to
                && 'subject' === $message->subject
                && 'body' === $message->body,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        new MessengerRecoveryMailSender(
            $dispatcher,
            $translator,
            $this->createStub(LoggerInterface::class),
            'ConfiguredServ',
        )->sendRecovery('Nick', 'user@example.com', 'safe-token', 'es');
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
            'NickServ RECOVER: failed to dispatch recovery email',
            self::callback(static function (array $context): bool {
                $serialized = serialize($context);

                return 'Nick' === $context['nick']
                    && 'user@example.com' === $context['recipient']
                    && RuntimeException::class === $context['exception_class']
                    && !str_contains($serialized, 'secret-token');
            }),
        );

        $sender = new MessengerRecoveryMailSender($dispatcher, $translator, $logger, 'NickServ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');
        $sender->sendRecovery('Nick', 'user@example.com', 'secret-token', 'en');
    }
}
