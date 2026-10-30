<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\MemoServ\Adapter\In\Irc\MemoNoticePresenter;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoNoticePresenter::class)]
final class MemoNoticePresenterTest extends TestCase
{
    #[Test]
    public function localizesAndRendersNickAndChannelNoticesWithoutReinterpolatingData(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnCallback(
            static fn (string $key, array $parameters, ?string $domain, ?string $locale): string => match ($key . ':' . $locale) {
                'notify.nick_pending:es' => '%count%: /msg %bot% %list%',
                'notify.channel_pending:fr' => '%channel% %count%: /msg %bot% %list%',
                default => 'unexpected',
            },
        );
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(
            components: ['emphasis' => new Appearance(bold: true), 'command' => new Appearance('03')],
            markers: ['information' => 'i'],
        )));
        $presenter = new MemoNoticePresenter($translator, $renderer);

        self::assertSame("i \x022\x03\x0F: /msg MemoServ \x0303LIST\x03\x0F", $presenter->nick(2, 'MemoServ', 'es'));
        self::assertSame("i \x02#%count%\x03\x0F \x023\x03\x0F: /msg MemoServ \x0303LIST #%count%\x03\x0F", $presenter->channel(3, '#%count%', 'MemoServ', 'fr'));
    }
}
