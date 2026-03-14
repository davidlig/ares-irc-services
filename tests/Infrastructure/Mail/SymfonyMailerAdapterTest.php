<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mail;

use App\Infrastructure\Mail\SymfonyMailerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface as SymfonyMailerInterface;
use Symfony\Component\Mime\Email;

#[CoversClass(SymfonyMailerAdapter::class)]
final class SymfonyMailerAdapterTest extends TestCase
{
    private SymfonyMailerInterface&MockObject $symfonyMailer;

    private SymfonyMailerAdapter $adapter;

    protected function setUp(): void
    {
        $this->symfonyMailer = $this->createMock(SymfonyMailerInterface::class);
        $this->adapter = new SymfonyMailerAdapter(
            $this->symfonyMailer,
            'noreply@irc.example.com',
            'MyIRC Network',
        );
    }

    #[Test]
    public function sendBuildsEmailWithFromToSubjectBodyAndDelegatesToSymfonyMailer(): void
    {
        $to = 'user@example.com';
        $subject = 'Test subject';
        $body = 'Plain text body';

        $this->symfonyMailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email) use ($to, $subject, $body): bool {
                $from = $email->getFrom();
                self::assertCount(1, $from);
                self::assertSame('noreply@irc.example.com', $from[0]->getAddress());
                self::assertSame('MyIRC Network', $from[0]->getName());

                $toAddresses = $email->getTo();
                self::assertCount(1, $toAddresses);
                self::assertSame($to, $toAddresses[0]->getAddress());

                self::assertSame($subject, $email->getSubject());

                $textBody = $email->getTextBody();
                self::assertIsString($textBody);
                self::assertSame($body, $textBody);

                return true;
            }));

        $this->adapter->send($to, $subject, $body);
    }

    #[Test]
    public function sendUsesSenderNameAsVisibleFromNameRegardlessOfFromAddressFormat(): void
    {
        $this->adapter = new SymfonyMailerAdapter(
            $this->symfonyMailer,
            'services@irc.example.com',
            'Ares Services',
        );

        $this->symfonyMailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                $from = $email->getFrom();
                self::assertCount(1, $from);
                self::assertSame('services@irc.example.com', $from[0]->getAddress());
                self::assertSame('Ares Services', $from[0]->getName());

                return true;
            }));

        $this->adapter->send('recipient@example.com', 'Subject', 'Body');
    }
}
