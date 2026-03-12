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
}
