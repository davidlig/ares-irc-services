<?php

declare(strict_types=1);

namespace App\Tests\Application\Mail\Message;

use App\Application\Mail\Message\SendEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendEmail::class)]
final class SendEmailTest extends TestCase
{
    #[Test]
    public function holdsToSubjectAndBody(): void
    {
        $msg = new SendEmail('user@example.com', 'Subject', 'Body text');

        self::assertSame('user@example.com', $msg->to);
        self::assertSame('Subject', $msg->subject);
        self::assertSame('Body text', $msg->body);
    }
}
