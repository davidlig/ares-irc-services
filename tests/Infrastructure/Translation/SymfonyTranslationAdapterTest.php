<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Translation;

use App\Infrastructure\Translation\SymfonyTranslationAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SymfonyTranslationAdapter::class)]
final class SymfonyTranslationAdapterTest extends TestCase
{
    #[Test]
    public function transDelegatesToSymfonyTranslator(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('nickserv.registered', ['%nick%' => 'Alice'], 'nickserv', 'es')
            ->willReturn('Registrado Alice');

        $adapter = new SymfonyTranslationAdapter($translator);

        self::assertSame(
            'Registrado Alice',
            $adapter->trans('nickserv.registered', ['%nick%' => 'Alice'], 'nickserv', 'es'),
        );
    }
}
