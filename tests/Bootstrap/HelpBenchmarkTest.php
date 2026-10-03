<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

#[CoversNothing]
final class HelpBenchmarkTest extends TestCase
{
    #[Test]
    public function benchmarksCompleteRegistriesAndLocalizedOutputWithoutRemoteServices(): void
    {
        [$status, $output, $errors] = $this->runBenchmark(['--iterations=2', '--warmups=1', '--locale=es', '--locales=all']);
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(['nickserv' => 23, 'chanserv' => 29, 'memoserv' => 8, 'operserv' => 8], $report['registered_commands']);
        self::assertSame(['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'], $report['catalog_locales']);
        self::assertSame(2, $report['iterations']);
        self::assertSame(1, $report['warmups']);
        self::assertIsArray($report['settings']);
        self::assertSame('off', $report['settings']['xdebug_mode_override']);
        self::assertSame([], $report['settings']['xdebug_effective_modes']);
        self::assertIsArray($report['timings']);
        self::assertCount(11, $report['timings']);
        foreach ($report['timings'] as $timing) {
            self::assertIsArray($timing);
            self::assertGreaterThan(0, $timing['lines']);
            self::assertGreaterThan(0, $timing['bytes']);
            self::assertIsString($timing['sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $timing['sha256']);
            self::assertIsInt($timing['write_line_calls']);
            self::assertIsInt($timing['write_lines_calls']);
            self::assertGreaterThan(0, $timing['write_line_calls'] + $timing['write_lines_calls']);
            foreach (['first_line_ms', 'last_line_ms'] as $metric) {
                self::assertIsArray($timing[$metric]);
                self::assertGreaterThanOrEqual(0, $timing[$metric]['median']);
                self::assertGreaterThanOrEqual($timing[$metric]['median'], $timing[$metric]['p95']);
            }
        }
        self::assertIsArray($report['outputs']);
        self::assertCount(5754, $report['outputs']);
        $general = [];
        foreach ($report['outputs'] as $row) {
            self::assertIsArray($row);
            self::assertGreaterThan(0, $row['lines']);
            self::assertGreaterThan(0, $row['bytes']);
            self::assertIsString($row['sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['sha256']);
            if ('HELP' === $row['command'] && 'en' === $row['locale']) {
                self::assertIsString($row['service']);
                self::assertIsString($row['actor']);
                $general[$row['service']][$row['actor']] = $row['sha256'];
            }
        }
        foreach (['nickserv', 'chanserv', 'operserv'] as $domain) {
            self::assertNotSame($general[$domain]['ordinary'], $general[$domain]['root']);
            self::assertNotSame($general[$domain]['oper'], $general[$domain]['root']);
        }
        self::assertSame($general['memoserv']['ordinary'], $general['memoserv']['root']);
    }

    #[Test]
    #[DataProvider('invalidOptions')]
    public function rejectsInvalidOptionsBeforeStartingReceiver(string $option): void
    {
        [$status, $output, $errors] = $this->runBenchmark([$option]);
        self::assertSame(2, $status);
        self::assertSame('', $output);
        self::assertStringContainsString('Usage:', $errors);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOptions(): iterable
    {
        yield 'no observations' => ['--iterations=0'];
        yield 'negative warmup' => ['--warmups=-1'];
        yield 'unknown timing locale' => ['--locale=zz'];
        yield 'unknown catalog locale' => ['--locales=en,zz'];
    }

    /** @param list<string> $options
     * @return array{int, string, string}
     */
    private function runBenchmark(array $options): array
    {
        $process = proc_open([PHP_BINARY, 'scripts/benchmark-help.php', ...$options], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), ['XDEBUG_MODE' => 'off']);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($errors);

        return [proc_close($process), $output, $errors];
    }
}
