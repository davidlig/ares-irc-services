<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function array_merge;
use function bin2hex;
use function chmod;
use function copy;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function stream_get_contents;
use function sys_get_temp_dir;

#[CoversNothing]
final class MakefileTest extends TestCase
{
    private string $directory;

    #[Test]
    public function runsPhpunitWithoutCoverageAndPropagatesFailures(): void
    {
        [$code, $output] = $this->runMake('test', ['TEST_ARGS=tests/SomeTest.php --filter selected']);
        self::assertSame(0, $code, $output);
        self::assertSame("--no-coverage\n--display-all-issues\ntests/SomeTest.php\n--filter\nselected\n", $output);
        [$code] = $this->runMake('test', [], ['STUB_EXIT' => '17']);
        self::assertNotSame(0, $code);
    }

    #[Test]
    public function coverageWrapsTheFullExactHundredGateAndPropagatesFailures(): void
    {
        [$code, $output] = $this->runMake('coverage');
        self::assertSame(0, $code, $output);
        self::assertSame("100\n--issues\n", $output);
        [$code] = $this->runMake('coverage', [], ['STUB_EXIT' => '17']);
        self::assertNotSame(0, $code);
    }

    #[Test]
    public function healthFailsClosedForEveryUnavailableOrUnhealthyContainer(): void
    {
        foreach ([['CONTAINER_ID' => ''], ['HEALTH_STATUS' => 'unhealthy'], ['HEALTH_STATUS' => 'starting'], ['INSPECT_FAIL' => '1'], ['PS_FAIL' => '1']] as $environment) {
            [$code] = $this->runMake('health', [], $environment);
            self::assertNotSame(0, $code);
        }
        [$code, $output] = $this->runMake('health');
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Healthy', $output);
    }

    #[Test]
    public function retainedComposeOperationsRespectProductionOverridesWithoutExecutingDocker(): void
    {
        foreach (['build', 'build-no-cache', 'up', 'down', 'logs', 'logs-tail', 'ps', 'shell', 'clean', 'restart', 'db-migrate', 'health'] as $target) {
            foreach (['', 'docker/compose.production.yaml'] as $override) {
                [$code, $output] = $this->runMake($target, ['-n', 'ARES_COMPOSE_OVERRIDE=' . $override]);
                self::assertSame(0, $code, $output);
                self::assertStringContainsString('./compose-spy -f docker/compose.yaml', $output);
                if ('' !== $override) {
                    self::assertStringContainsString('-f ' . $override, $output);
                }
            }
        }
        [$code, $output] = $this->runMake('clean', ['-n']);
        self::assertSame(0, $code);
        self::assertStringContainsString('down -v --rmi local', $output);
        self::assertStringContainsString('rm -rf var/cache/* var/log/*', $output);
        $makefile = (string) file_get_contents($this->directory . '/Makefile');
        self::assertMatchesRegularExpression('/^\.PHONY:.*\bbuild-multiarch\b/m', $makefile);
        foreach (['db-shell', 'db-backup', 'db-restore', 'config-show'] as $removed) {
            [$code] = $this->runMake($removed);
            self::assertNotSame(0, $code);
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ares-make-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/vendor/bin', 0o700, true);
        mkdir($this->directory . '/scripts', 0o700);
        copy(dirname(__DIR__, 2) . '/Makefile', $this->directory . '/Makefile');
        $this->stub('vendor/bin/phpunit', '#!/bin/sh' . "\nprintf '%s\\n' \"\$@\"\nexit \"\${STUB_EXIT:-0}\"\n");
        $this->stub('scripts/check-coverage.sh', '#!/bin/sh' . "\nprintf '%s\\n' \"\$@\"\nexit \"\${STUB_EXIT:-0}\"\n");
        $this->stub('compose-spy', '#!/bin/sh' . "\n[ \"\${PS_FAIL:-0}\" = 0 ] || exit 1\nprintf '%s' \"\${CONTAINER_ID:-}\"\n");
        $this->stub('docker', '#!/bin/sh' . "\n[ \"\$1\" = inspect ] || exit 91\n[ \"\${INSPECT_FAIL:-0}\" = 0 ] || exit 1\nprintf '%s' \"\${HEALTH_STATUS:-healthy}\"\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function stub(string $path, string $script): void
    {
        file_put_contents($this->directory . '/' . $path, $script);
        chmod($this->directory . '/' . $path, 0o700);
    }

    /** @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function runMake(string $target, array $arguments = [], array $environment = []): array
    {
        $process = proc_open(['make', '--no-print-directory', '-s', $target, 'DOCKER_COMPOSE=./compose-spy', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory, array_merge(getenv(), ['PATH' => $this->directory . ':' . getenv('PATH'), 'CONTAINER_ID' => 'synthetic-container'], $environment));
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $errors];
    }
}
