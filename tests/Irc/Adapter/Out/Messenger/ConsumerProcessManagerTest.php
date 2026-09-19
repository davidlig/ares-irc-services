<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Messenger;

use App\Irc\Adapter\Out\Messenger\ConsumerProcessManager;
use App\Irc\Adapter\Runtime\LoopSchedulerInterface;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_slice;
use function count;
use function is_array;
use function proc_terminate;

use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const SIGKILL;

#[CoversClass(ConsumerProcessManager::class)]
final class ConsumerProcessManagerTest extends TestCase
{
    #[Test]
    public function isRunningReturnsFalseWhenProcessNotStarted(): void
    {
        $manager = new ConsumerProcessManager('/nonexistent');

        self::assertFalse($manager->isRunning());
    }

    #[Test]
    public function stopWhenNotStartedDoesNothing(): void
    {
        $manager = new ConsumerProcessManager('/nonexistent');

        $manager->stop();

        self::assertFalse($manager->isRunning());
    }

    #[Test]
    public function startIsIdempotentWhenProcessAlreadyStarted(): void
    {
        $script = $this->createTemporaryConsoleScript();
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();
            $manager->start();

            $manager->stop();
            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function isRunningReturnsTrueWhileProcessIsAliveThenFalseAfterStop(): void
    {
        $script = $this->createTemporaryConsoleScriptBlocking();
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();

            self::assertTrue($manager->isRunning());
            $manager->stop();
            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    private function createTemporaryConsoleScript(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ares_console_');
        if (false === $tmp) {
            self::fail('Could not create temp file');
        }
        $path = $tmp . '.php';
        rename($tmp, $path);
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nexit(0);\n");

        return $path;
    }

    private function createTemporaryConsoleScriptBlocking(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ares_console_');
        if (false === $tmp) {
            self::fail('Could not create temp file');
        }
        $path = $tmp . '.php';
        rename($tmp, $path);
        // Block on stdin — process runs until pipes are closed by stop()
        file_put_contents($path, "<?php fgets(STDIN);\n");

        return $path;
    }

    #[Test]
    public function stopClosesPipeResources(): void
    {
        $script = $this->createTemporaryConsoleScriptBlocking();
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();

            $reflection = new ReflectionClass($manager);
            $pipesProperty = $reflection->getProperty('pipes');
            $pipes = $pipesProperty->getValue($manager);

            self::assertNotNull($pipes);
            self::assertIsArray($pipes);
            foreach ($pipes as $pipe) {
                self::assertIsResource($pipe);
            }

            $manager->stop();

            $pipesAfterStop = $pipesProperty->getValue($manager);
            self::assertNull($pipesAfterStop);
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function startWithCustomTransportNames(): void
    {
        $script = $this->createTemporaryConsoleScriptBlocking();
        try {
            $manager = new ConsumerProcessManager($script, ['async', 'async_emails']);

            $manager->start();

            self::assertTrue($manager->isRunning());
            $manager->stop();
            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function stopWhenProcessAlreadyTerminated(): void
    {
        $script = $this->createTemporaryConsoleScript();
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();
            $manager->stop();
            $manager->stop();

            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function isRunningReturnsFalseWhenProcessResourceIsNotValid(): void
    {
        $script = $this->createTemporaryConsoleScript();
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();
            $manager->stop();

            $reflection = new ReflectionClass($manager);
            $processProperty = $reflection->getProperty('process');
            $processProperty->setValue($manager, null);

            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function supervisorRestartsExitedConsumerWithResetAndRecycleLimits(): void
    {
        $record = tempnam(sys_get_temp_dir(), 'ares_consumer_args_');
        self::assertIsString($record);
        $script = $this->createTemporaryConsoleScriptRecordingArguments($record);
        $now = 0.0;
        $scheduler = new class implements LoopSchedulerInterface {
            /** @var Closure(string): void|null */
            public ?Closure $callback = null;

            public bool $cancelled = false;

            public int $repeatCount = 0;

            public function repeat(float $intervalSeconds, Closure $callback): string
            {
                TestCase::assertSame(1.0, $intervalSeconds);
                ++$this->repeatCount;
                $this->callback = $callback;

                return 'consumer-supervisor';
            }

            public function delay(float $delaySeconds, Closure $callback): string
            {
                TestCase::fail('Consumer supervisor must not schedule a one-shot timer.');
            }

            public function cancel(string $watcherId): void
            {
                TestCase::assertSame('consumer-supervisor', $watcherId);
                $this->cancelled = true;
            }
        };

        try {
            $manager = new ConsumerProcessManager($script, ['async'], $scheduler, static function () use (&$now): float {
                return $now;
            });
            $manager->start();
            self::assertNotNull($scheduler->callback);

            $deadline = microtime(true) + 2.0;
            while ($manager->isRunning() && microtime(true) < $deadline) {
                // The child exits immediately; poll its OS status without sleeping.
            }
            self::assertFalse($manager->isRunning());

            ($scheduler->callback)('consumer-supervisor');
            self::assertFalse($manager->isRunning());
            $manager->start();
            self::assertSame(1, $scheduler->repeatCount);
            $now = 1.0;
            ($scheduler->callback)('consumer-supervisor');
            $deadline = microtime(true) + 2.0;
            while ($manager->isRunning() && microtime(true) < $deadline) {
                // Wait for the restarted child to record its arguments.
            }
            self::assertFalse($manager->isRunning());
            ($scheduler->callback)('consumer-supervisor');
            $now = 2.0;
            ($scheduler->callback)('consumer-supervisor');
            self::assertFalse($manager->isRunning());
            $now = 3.0;
            ($scheduler->callback)('consumer-supervisor');
            $deadline = microtime(true) + 2.0;
            do {
                $recordedLines = file($record, FILE_IGNORE_NEW_LINES);
            } while (is_array($recordedLines) && count($recordedLines) < 3 && microtime(true) < $deadline);
            if (!is_array($recordedLines)) {
                self::fail('Could not read recorded Messenger arguments.');
            }
            $manager->stop();

            $lines = file($record, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            self::assertCount(3, $lines);
            $args = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($args);
            self::assertSame(['messenger:consume', 'async', '--time-limit=3600', '--memory-limit=384M'], array_slice($args, 1));
            self::assertTrue($scheduler->cancelled);
        } finally {
            @unlink($script);
            @unlink($record);
        }
    }

    #[Test]
    public function supervisorKeepsAHealthyConsumerAndResetsFailureBackoff(): void
    {
        $script = $this->createTemporaryConsoleScriptBlocking();
        $now = 0.0;
        $scheduler = new class implements LoopSchedulerInterface {
            /** @var Closure(string): void|null */
            public ?Closure $callback = null;

            public function repeat(float $intervalSeconds, Closure $callback): string
            {
                $this->callback = $callback;

                return 'supervisor';
            }

            public function delay(float $delaySeconds, Closure $callback): string
            {
                TestCase::fail('Consumer supervisor must not schedule a one-shot timer.');
            }

            public function cancel(string $watcherId): void {}
        };

        try {
            $manager = new ConsumerProcessManager($script, ['async'], $scheduler, static function () use (&$now): float {
                return $now;
            });
            $manager->start();
            self::assertTrue($manager->isRunning());
            self::assertNotNull($scheduler->callback);

            $reflection = new ReflectionClass($manager);
            $restartDelay = $reflection->getProperty('restartDelaySeconds');
            $restartDelay->setValue($manager, 30);
            $now = 61.0;

            ($scheduler->callback)('supervisor');

            self::assertTrue($manager->isRunning());
            self::assertSame(1, $restartDelay->getValue($manager));
            $manager->stop();
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function supervisorAppliesBackoffWhenALongRunnerIsKilledBySignal(): void
    {
        $script = $this->createTemporaryConsoleScriptBlocking();
        $now = 0.0;
        $scheduler = new class implements LoopSchedulerInterface {
            /** @var Closure(string): void|null */
            public ?Closure $callback = null;

            public function repeat(float $intervalSeconds, Closure $callback): string
            {
                $this->callback = $callback;

                return 'supervisor';
            }

            public function delay(float $delaySeconds, Closure $callback): string
            {
                TestCase::fail('Consumer supervisor must not schedule a one-shot timer.');
            }

            public function cancel(string $watcherId): void {}
        };

        try {
            $manager = new ConsumerProcessManager($script, ['async'], $scheduler, static function () use (&$now): float {
                return $now;
            });
            $manager->start();
            self::assertNotNull($scheduler->callback);

            $reflection = new ReflectionClass($manager);
            $process = $reflection->getProperty('process')->getValue($manager);
            self::assertIsResource($process);
            proc_terminate($process, SIGKILL);

            $deadline = microtime(true) + 2.0;
            while ($manager->isRunning() && microtime(true) < $deadline) {
            }

            $now = 61.0;
            ($scheduler->callback)('supervisor');

            $restartDelay = $reflection->getProperty('restartDelaySeconds');
            self::assertSame(2, $restartDelay->getValue($manager));
            self::assertFalse($manager->isRunning());

            $now = 62.0;
            ($scheduler->callback)('supervisor');

            self::assertIsResource($reflection->getProperty('process')->getValue($manager));

            $manager->stop();
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function stopForceKillsAConsumerThatIgnoresSigterm(): void
    {
        $ready = tempnam(sys_get_temp_dir(), 'ares_consumer_ready_');
        self::assertIsString($ready);
        @unlink($ready);
        $script = $this->createTemporaryConsoleScriptIgnoringSigterm($ready);

        try {
            $manager = new ConsumerProcessManager($script);
            $manager->start();
            $deadline = microtime(true) + 2.0;
            while (!file_exists($ready) && microtime(true) < $deadline) {
                // Wait for the child to install its signal handler before stopping it.
            }
            self::assertFileExists($ready);

            $manager->stop();

            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
            @unlink($ready);
        }
    }

    private function createTemporaryConsoleScriptRecordingArguments(string $record): string
    {
        $script = $this->createTemporaryConsoleScript();
        file_put_contents($script, '<?php file_put_contents(' . var_export($record, true) . ', json_encode($argv) . "\\n", FILE_APPEND); exit(1);');

        return $script;
    }

    private function createTemporaryConsoleScriptIgnoringSigterm(string $ready): string
    {
        $script = $this->createTemporaryConsoleScript();
        file_put_contents(
            $script,
            '<?php pcntl_async_signals(true); pcntl_signal(SIGTERM, static function (): void {}); file_put_contents('
            . var_export($ready, true)
            . ", 'ready'); while (true) { usleep(10_000); }",
        );

        return $script;
    }
}
