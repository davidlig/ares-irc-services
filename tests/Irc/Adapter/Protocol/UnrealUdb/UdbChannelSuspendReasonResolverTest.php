<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UdbChannelSuspendReasonResolver::class)]
final class UdbChannelSuspendReasonResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheTranslatedPendingDeletionReason(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'drop.pending_deletion_reason',
                ['%channel%' => '#Test2', '%bot%' => 'ChanServ'],
                'chanserv',
                'es',
            )
            ->willReturn('El canal #Test2 está en proceso de eliminación.');

        $resolver = new UdbChannelSuspendReasonResolver($translator, 'ChanServ', 'es');

        self::assertSame(
            'El canal #Test2 está en proceso de eliminación.',
            $resolver->pendingDeletionReason('#Test2'),
        );
    }

    #[Test]
    public function defaultsToEnglishWhenNoLanguageIsConfigured(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'drop.pending_deletion_reason',
                ['%channel%' => '#Test2', '%bot%' => 'ChanServ'],
                'chanserv',
                'en',
            )
            ->willReturn('reason');

        $resolver = new UdbChannelSuspendReasonResolver($translator, 'ChanServ');

        self::assertSame('reason', $resolver->pendingDeletionReason('#Test2'));
    }
}
