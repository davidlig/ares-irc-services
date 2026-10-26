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
                'registration_decision.rejected' => 'Rejected %channel% by %operator%.',
                'registration_decision.reason' => 'Reason: %reason%',
                'registration_decision.restriction' => 'Restriction remaining: %remaining_seconds% seconds.',
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
            new DateTimeImmutable('2026-10-26T10:00:30+00:00'),
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
        );

        $messages = new ChannelRegistrationMemoTextAdapter($translator)->render($input, 'es');

        self::assertGreaterThan(1, count($messages));
        foreach ($messages as $message) {
            self::assertLessThanOrEqual(255, strlen($message));
            self::assertTrue(mb_check_encoding($message, 'UTF-8'));
        }
        $combined = implode('', $messages);
        self::assertStringContainsString('Rejected #chan by Op.', $combined);
        self::assertStringContainsString('Reason: ' . str_repeat('é', 200), $combined);
        self::assertStringContainsString('Restriction remaining: 30 seconds.', $combined);
    }

    #[Test]
    public function rendersIndefiniteRestrictionForRejection(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(3))->method('trans')->willReturnMap([
            ['registration_decision.rejected', ['%channel%' => '#chan', '%operator%' => 'Op'], 'memoserv', 'en', 'Rejected #chan by Op.'],
            ['registration_decision.reason', ['%reason%' => 'policy'], 'memoserv', 'en', 'Reason: policy'],
            ['registration_decision.restriction_indefinite', [], 'memoserv', 'en', 'Restricted indefinitely.'],
        ]);
        $input = new NotifyChannelRegistrationDecision(4, '#chan', 12, 7, 'Op', false, 'policy', null, new DateTimeImmutable('2026-10-26T10:00:00+00:00'));

        $messages = new ChannelRegistrationMemoTextAdapter($translator)->render($input, 'en');

        self::assertSame(['Rejected #chan by Op. Reason: policy Restricted indefinitely.'], $messages);
    }

    #[Test]
    public function rendersAcceptedDecisionWithoutRejectionMetadata(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with(
            'registration_decision.accepted',
            ['%channel%' => '#chan', '%operator%' => 'Op'],
            'memoserv',
            'fr',
        )->willReturn('Accepted #chan by Op.');

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

        self::assertSame(['Accepted #chan by Op.'], $messages);
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
