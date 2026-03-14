<?php

declare(strict_types=1);

namespace App\Tests\Application\Mail\Message;

use App\Application\Mail\MailerInterface;
use App\Application\Mail\Message\SendEmail;
use App\Application\Mail\Message\SendEmailHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendEmailHandler::class)]
final class SendEmailHandlerTest extends TestCase
{
    #[Test]
    public function invokeCallsMailerWithMessageData(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->with('to@example.com', 'Subject', 'Body content');

        $handler = new SendEmailHandler($mailer);
        $handler(new SendEmail('to@example.com', 'Subject', 'Body content'));
    }

    #[Test]
    public function invokeForwardsEmptyBodyAndSubjectToMailer(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->with('a@b.co', '', '');

        $handler = new SendEmailHandler($mailer);
        $handler(new SendEmail('a@b.co', '', ''));
    }

    #[Test]
    public function eachInvocationCallsMailerOnce(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('send')
            ->willReturnCallback(static function (): void {});

        $handler = new SendEmailHandler($mailer);
        $handler(new SendEmail('one@example.com', 'First', 'Body one'));
        $handler(new SendEmail('two@example.com', 'Second', 'Body two'));
    }
}
