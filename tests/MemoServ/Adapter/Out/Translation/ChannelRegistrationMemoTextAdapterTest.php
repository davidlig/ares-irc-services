<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Translation;

use App\MemoServ\Adapter\Out\Translation\ChannelRegistrationMemoTextAdapter;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function implode;
use function mb_check_encoding;
use function str_repeat;
use function strlen;
use function strtr;

#[CoversClass(ChannelRegistrationMemoTextAdapter::class)]
final class ChannelRegistrationMemoTextAdapterTest extends TestCase
{
    #[Test]
    public function rendersLocalizedOutcomeAndRestrictionThenSplitsWithoutLosingUnicodeText(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(3))->method('trans')->willReturnCallback(static function (string $id, array $parameters, ?string $domain, ?string $locale): string {
            self::assertSame('memoserv', $domain);
            self::assertSame('es', $locale);
            $template = match ($id) {
                'registration_decision.rejected' => 'Rejected %channel%.',
                'registration_decision.reason' => 'Reason: %reason%',
                'registration_decision.restriction' => 'Available from %date%.',
                default => $id,
            };

            return strtr($template, $parameters);
        });
        $input = new NotifyChannelRegistrationDecision(
            4,
            '#chan',
            12,
            7,
            'Op',
            false,
            str_repeat('é', 200),
            new DateTimeImmutable('2026-10-26T12:00:30+02:00'),
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
        );

        $messages = new ChannelRegistrationMemoTextAdapter($translator)->render($input, 'es');

        self::assertGreaterThan(1, count($messages));
        foreach ($messages as $message) {
            self::assertLessThanOrEqual(255, strlen($message));
            self::assertTrue(mb_check_encoding($message, 'UTF-8'));
        }
        $combined = implode('', $messages);
        self::assertStringContainsString('Rejected #chan.', $combined);
        self::assertStringContainsString('Reason: ' . str_repeat('é', 200), $combined);
        self::assertStringContainsString('Available from 26/10/2026 10:00 UTC.', $combined);
    }

    #[Test]
    public function omitsIndefiniteRestrictionForRejection(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnMap([
            ['registration_decision.rejected', ['%channel%' => '#chan'], 'memoserv', 'en', 'Rejected #chan.'],
            ['registration_decision.reason', ['%reason%' => 'policy'], 'memoserv', 'en', 'Reason: policy'],
        ]);
        $input = new NotifyChannelRegistrationDecision(4, '#chan', 12, 7, 'Op', false, 'policy', null, new DateTimeImmutable('2026-10-26T10:00:00+00:00'));

        $messages = new ChannelRegistrationMemoTextAdapter($translator)->render($input, 'en');

        self::assertSame(['Rejected #chan. Reason: policy'], $messages);
    }

    #[Test]
    public function rendersAcceptedDecisionWithoutRejectionMetadata(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with(
            'registration_decision.accepted',
            ['%channel%' => '#chan'],
            'memoserv',
            'fr',
        )->willReturn('Accepted #chan.');

        $messages = new ChannelRegistrationMemoTextAdapter($translator)->render(new NotifyChannelRegistrationDecision(
            4,
            '#chan',
            12,
            7,
            'Op',
            true,
            null,
            null,
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
        ), 'fr');

        self::assertSame(['Accepted #chan.'], $messages);
    }

    #[Test]
    public function rejectsMalformedRejectionEventWithoutReason(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $this->expectException(InvalidArgumentException::class);
        new ChannelRegistrationMemoTextAdapter($translator)->render(new NotifyChannelRegistrationDecision(
            4,
            '#chan',
            12,
            7,
            'Op',
            false,
            null,
            null,
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
        ), 'en');
    }

    #[Test]
    public function rejectsInvalidUtf8InsteadOfLoopingOrLosingMemoContent(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn("\xFF");

        $this->expectException(InvalidArgumentException::class);
        new ChannelRegistrationMemoTextAdapter($translator)->render(new NotifyChannelRegistrationDecision(
            4,
            '#chan',
            12,
            7,
            'Op',
            true,
            null,
            null,
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
        ), 'en');
    }
}
