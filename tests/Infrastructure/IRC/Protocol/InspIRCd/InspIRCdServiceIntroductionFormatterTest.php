<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdServiceIntroductionFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(InspIRCdServiceIntroductionFormatter::class)]
final class InspIRCdServiceIntroductionFormatterTest extends TestCase
{
    private InspIRCdServiceIntroductionFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new InspIRCdServiceIntroductionFormatter();
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
        self::assertStringContainsString('001ABCD', $line);
        self::assertStringContainsString('NickServ', $line);
        self::assertStringContainsString('services', $line);
        self::assertStringContainsString('services.test.local', $line);
        self::assertStringContainsString('+oIk', $line);
        self::assertStringContainsString(':Nickname Service', $line);
    }

    #[Test]
    public function formatIntroductionUsesInspIRCdFormat(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '002',
            nick: 'ChanServ',
            ident: 'services',
            host: 'services.test.local',
            uid: '002EFGH',
            realname: 'Channel Service'
        );

        self::assertMatchesRegularExpression(
            '/^:002 UID 002EFGH \d+ ChanServ services\.test\.local services\.test\.local services services 0\.0\.0\.0 \d+ \+oIk :Channel Service$/',
            $line
        );
    }

    #[Test]
    public function formatIntroductionContainsModeString(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '003',
            nick: 'MemoServ',
            ident: 'services',
            host: 'services.test.local',
            uid: '003IJKL',
            realname: 'Memo Service'
        );

        self::assertStringContainsString('+oIk', $line);
    }

    #[Test]
    public function formatIntroductionIncludesTimestampTwice(): void
    {
        $line = $this->formatter->formatIntroduction(
            serverSid: '001',
            nick: 'TestBot',
            ident: 'test',
            host: 'test.local',
            uid: '001TEST',
            realname: 'Test Bot'
        );

        self::assertMatchesRegularExpression('/:001 UID 001TEST \d+/', $line);
        $matches = [];
        preg_match_all('/\d+/', $line, $matches);
        $numbers = $matches[0];
        self::assertGreaterThan(1, count($numbers));
    }
}
