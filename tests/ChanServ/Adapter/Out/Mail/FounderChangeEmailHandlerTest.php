<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Mail;

use App\ChanServ\Adapter\Out\Mail\FounderChangeEmail;
use App\ChanServ\Adapter\Out\Mail\FounderChangeEmailHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

use function array_map;

#[CoversClass(FounderChangeEmailHandler::class)]
#[CoversClass(FounderChangeEmail::class)]
final class FounderChangeEmailHandlerTest extends TestCase
{
    #[Test]
    public function sendsPlainTextMailWithConfiguredSender(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(
            static fn (RawMessage $message): bool => $message instanceof Email
                && ['user@example.com'] === array_map(static fn ($address): string => $address->getAddress(), $message->getTo())
                && 'Ares Network' === $message->getFrom()[0]->getName()
                && 'services@example.com' === $message->getFrom()[0]->getAddress()
                && 'Subject' === $message->getSubject()
                && 'Body' === $message->getTextBody(),
        ));
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())->method('sleep')->with(5);

        $handler = new FounderChangeEmailHandler(
            $mailer,
            $clock,
            'Services <services@example.com>',
            'Ares Network',
            5,
        );

        $handler(new FounderChangeEmail('user@example.com', 'Subject', 'Body'));
    }

    #[Test]
    public function skipsDelayWhenItIsDisabled(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('sleep');

        $handler = new FounderChangeEmailHandler(
            $mailer,
            $clock,
            'services@example.com',
            'Ares Network',
            0,
        );

        $handler(new FounderChangeEmail('user@example.com', 'Subject', 'Body'));
    }
}
