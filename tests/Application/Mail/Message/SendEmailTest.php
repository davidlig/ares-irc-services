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

    #[Test]
    public function acceptsEmptyStrings(): void
    {
        $msg = new SendEmail('', '', '');

        self::assertSame('', $msg->to);
        self::assertSame('', $msg->subject);
        self::assertSame('', $msg->body);
    }

    #[Test]
    public function preservesUnicodeInSubjectAndBody(): void
    {
        $subject = 'Asunto: ñoño café';
        $body = "Cuerpo con\nsaltos y unicode: 日本語";

        $msg = new SendEmail('user@example.com', $subject, $body);

        self::assertSame('user@example.com', $msg->to);
        self::assertSame($subject, $msg->subject);
        self::assertSame($body, $msg->body);
    }
}
