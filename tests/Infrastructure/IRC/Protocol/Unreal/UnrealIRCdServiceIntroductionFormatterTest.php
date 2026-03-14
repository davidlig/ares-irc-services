<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdServiceIntroductionFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealIRCdServiceIntroductionFormatter::class)]
final class UnrealIRCdServiceIntroductionFormatterTest extends TestCase
{
    private UnrealIRCdServiceIntroductionFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new UnrealIRCdServiceIntroductionFormatter();
    }

    #[Test]
    public function formatIntroductionProducesValidUidLine(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '001',
            nick: 'NickServ',
            ident: 'services',
            host: 'services.test.local',
            uid: '001ABCD',
            realname: 'Nickname Service'
        );

        self::assertStringStartsWith(':001 UID ', $line);
        self::assertStringContainsString('NickServ', $line);
        self::assertStringContainsString('services', $line);
        self::assertStringContainsString('services.test.local', $line);
        self::assertStringContainsString('001ABCD', $line);
        self::assertStringContainsString('+Siod', $line);
        self::assertStringContainsString(':Nickname Service', $line);
    }

    #[Test]
    public function formatIntroductionContainsCorrectHopcount(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '002',
            nick: 'ChanServ',
            ident: 'services',
            host: 'services.test.local',
            uid: '002EFGH',
            realname: 'Channel Service'
        );

        self::assertMatchesRegularExpression('/:002 UID ChanServ 1 \d+/', $line);
    }

    #[Test]
    public function formatIntroductionUsesUnrealFormat(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '003',
            nick: 'MemoServ',
            ident: 'services',
            host: 'services.test.local',
            uid: '003IJKL',
            realname: 'Memo Service'
        );

        self::assertMatchesRegularExpression(
            '/^:003 UID MemoServ 1 \d+ services services\.test\.local 003IJKL 0 \+Siod \* \* \* :Memo Service$/',
            $line
        );
    }

    #[Test]
    public function formatIntroductionEscapesSpecialCharactersInRealname(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '001',
            nick: 'TestBot',
            ident: 'test',
            host: 'test.local',
            uid: '001TEST',
            realname: 'Test Bot Service'
        );

        self::assertStringEndsWith(':Test Bot Service', $line);
    }
}
