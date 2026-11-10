<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function file_get_contents;

#[CoversNothing]
final class IrcLoggingConfigurationTest extends TestCase
{
    #[Test]
    public function ircLogLevelUsesEnvironmentSpecificDefaults(): void
    {
        $root = dirname(__DIR__, 2);
        $dotenv = new Dotenv();
        $base = $dotenv->parse((string) file_get_contents($root . '/.env'));
        $dev = $dotenv->parse((string) file_get_contents($root . '/.env.dev'));
        $test = $dotenv->parse((string) file_get_contents($root . '/.env.test'));
        $config = Yaml::parseFile($root . '/config/packages/monolog.yaml');

        self::assertSame('info', $base['IRC_LOG_LEVEL']);
        self::assertSame('debug', $dev['IRC_LOG_LEVEL']);
        self::assertSame('debug', $test['IRC_LOG_LEVEL']);
        self::assertIsArray($config);
        self::assertIsArray($config['monolog']);
        self::assertIsArray($config['monolog']['handlers']);
        self::assertIsArray($config['monolog']['handlers']['irc_rotating']);
        self::assertSame('%env(IRC_LOG_LEVEL)%', $config['monolog']['handlers']['irc_rotating']['level']);
    }
}
