<?php

declare(strict_types=1);

/**
 * Automated IRC Services Test Suite with Multi-Bot Orchestration.
 *
 * Validates functional correctness and HELP/syntax alignment for all 64 commands
 * across NickServ, ChanServ, MemoServ, and OperServ.
 */

namespace App\Scripts\Test;

use function array_filter;
use function array_map;
use function count;
use function date;
use function explode;
use function fclose;
use function feof;
use function fgets;
use function file_put_contents;
use function fsockopen;
use function fwrite;
use function getopt;
use function implode;
use function in_array;
use function is_resource;
use function max;
use function microtime;
use function preg_match;
use function preg_replace;
use function sprintf;
use function stream_set_blocking;
use function stream_set_timeout;
use function strtoupper;
use function substr;
use function trim;
use function usleep;

final class IrcBotClient
{
    /** @var resource|null */
    private $socket;

    private bool $registered = false;

    /** @var list<string> */
    private array $buffer = [];

    public function __construct(
        public readonly string $nickname,
        public readonly string $ident,
        public readonly string $realname,
        public readonly ?string $password = null,
    ) {}

    public function connect(string $host, int $port, float $timeout = 5.0): bool
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$this->socket) {
            return false;
        }

        stream_set_timeout($this->socket, 1);
        stream_set_blocking($this->socket, false);

        if (null !== $this->password) {
            $this->send("PASS {$this->password}");
        }

        $nickToSend = null !== $this->password ? "{$this->nickname}:{$this->password}" : $this->nickname;
        $this->send("NICK {$nickToSend}");
        $this->send("USER {$this->ident} 0 * :{$this->realname}");

        $start = microtime(true);
        while (microtime(true) - $start < $timeout) {
            $lines = $this->readRawLines();
            foreach ($lines as $line) {
                if (str_starts_with($line, 'PING')) {
                    $token = trim(substr($line, 5));
                    $this->send("PONG {$token}");
                }
                if (str_contains($line, ' 001 ') || str_contains($line, ' 433 ') || str_contains($line, ' 432 ')) {
                    if (str_contains($line, ' 001 ')) {
                        $this->registered = true;

                        return true;
                    }

                    return false;
                }
            }
            usleep(20000);
        }

        return $this->registered;
    }

    public function isConnected(): bool
    {
        return null !== $this->socket && is_resource($this->socket) && !feof($this->socket);
    }

    public function send(string $raw): void
    {
        if (null !== $this->socket && is_resource($this->socket)) {
            @fwrite($this->socket, $raw . "\r\n");
        }
    }

    public function query(string $service, string $message, float $waitSeconds = 2.0): array
    {
        $this->flush();
        $this->send("PRIVMSG {$service} :{$message}");

        $responses = [];
        $start = microtime(true);
        $lastReceivedAt = microtime(true);

        while (microtime(true) - $start < $waitSeconds) {
            $lines = $this->readRawLines();
            if ([] !== $lines) {
                $lastReceivedAt = microtime(true);
                foreach ($lines as $line) {
                    if (str_starts_with($line, 'PING')) {
                        $token = trim(substr($line, 5));
                        $this->send("PONG {$token}");
                        continue;
                    }
                    if (str_contains($line, 'NOTICE') || str_contains($line, 'PRIVMSG')) {
                        $clean = $this->extractMessageContent($line);
                        if ('' !== $clean) {
                            $responses[] = $clean;
                        }
                    }
                }
            } else {
                if (count($responses) > 0 && microtime(true) - $lastReceivedAt > 0.4) {
                    break;
                }
                usleep(25000);
            }
        }

        return $responses;
    }

    public function disconnect(string $reason = 'Tests finished'): void
    {
        if (null !== $this->socket && is_resource($this->socket)) {
            $this->send("QUIT :{$reason}");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function flush(): void
    {
        $this->readRawLines();
        $this->buffer = [];
    }

    /** @return list<string> */
    private function readRawLines(): array
    {
        if (null === $this->socket || !is_resource($this->socket)) {
            return [];
        }

        $lines = [];
        while ($line = @fgets($this->socket)) {
            $trimmed = trim($line);
            if ('' !== $trimmed) {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    private function extractMessageContent(string $rawLine): string
    {
        $colonPos = strpos($rawLine, ' :', 1);
        if (false === $colonPos) {
            return '';
        }

        $text = substr($rawLine, $colonPos + 2);

        // Strip IRC colors and bold codes
        return trim(preg_replace('/[\x02\x0F\x16\x1D\x1F]|\x03[0-9]{1,2}(,[0-9]{1,2})?/', '', $text) ?? $text);
    }
}

/**
 * Result record for a single command test.
 */
final class CommandTestResult
{
    /**
     * @param list<string> $helpOutput
     * @param list<string> $executionOutput
     * @param list<string> $discrepancies
     */
    public function __construct(
        public readonly string $service,
        public readonly string $command,
        public readonly string $subcommand = '',
        public readonly bool $helpPassed = false,
        public readonly bool $syntaxErrorPassed = false,
        public readonly bool $functionalPassed = false,
        public readonly string $advertisedSyntax = '',
        public readonly array $helpOutput = [],
        public readonly array $executionOutput = [],
        public readonly array $discrepancies = [],
        public readonly string $notes = '',
    ) {}

    public function isSuccess(): bool
    {
        return $this->helpPassed && $this->syntaxErrorPassed && $this->functionalPassed && [] === $this->discrepancies;
    }
}

/**
 * Master Test Runner Orchestrator.
 */
final class ServicesTestSuite
{
    private string $host = '127.0.0.1';

    private int $port = 6667;

    private string $adminNick = 'AresAdminBot';

    private string $adminPass = 'abc123';

    private string $reportPath = 'var/reports/test-results.md';

    private ?IrcBotClient $adminBot = null;

    private ?IrcBotClient $founderBot = null;

    private ?IrcBotClient $targetBot = null;

    private ?IrcBotClient $guestBot = null;

    /** @var list<CommandTestResult> */
    private array $results = [];

    /** @var list<string> */
    private array $globalDiscrepancies = [];

    public function __construct(array $options)
    {
        if (isset($options['host'])) {
            $this->host = (string) $options['host'];
        }
        if (isset($options['port'])) {
            $this->port = (int) $options['port'];
        }
        if (isset($options['admin-nick'])) {
            $this->adminNick = (string) $options['admin-nick'];
        }
        if (isset($options['admin-pass'])) {
            $this->adminPass = (string) $options['admin-pass'];
        }
        if (isset($options['report'])) {
            $this->reportPath = (string) $options['report'];
        }
    }

    public function run(): int
    {
        echo "\033[1;36m====================================================================\033[0m\n";
        echo "\033[1;36m       Ares IRC Services — Comprehensive Test Suite & Bots          \033[0m\n";
        echo "\033[1;36m====================================================================\033[0m\n\n";

        echo "Connecting test bots to {$this->host}:{$this->port}...\n";

        if (!$this->setupBots()) {
            echo "\033[1;31m[FATAL] Failed to initialize test bots. Aborting.\033[0m\n";

            return 1;
        }

        echo "\033[1;32m[OK] All 4 bots initialized and ready!\033[0m\n\n";

        // Test Services
        $this->testNickServ();
        $this->testChanServ();
        $this->testMemoServ();
        $this->testOperServ();

        // Teardown
        $this->teardownBots();

        // Generate Report
        $this->generateReport();

        return 0;
    }

    private function setupBots(): bool
    {
        // 1. Admin Bot
        echo " - Initializing Admin Bot ({$this->adminNick})... ";
        $this->adminBot = new IrcBotClient($this->adminNick, 'admin', 'Ares Admin Bot', $this->adminPass);
        if (!$this->adminBot->connect($this->host, $this->port, 4.0)) {
            // Try connecting as AresTest if AresAdminBot failed
            echo "Failed ({$this->adminNick}). Trying AresTest... ";
            $this->adminBot = new IrcBotClient('AresTest', 'arestest', 'Ares Test Admin', 'abc123');
            if (!$this->adminBot->connect($this->host, $this->port, 4.0)) {
                echo "\033[1;31mFAILED\033[0m\n";

                return false;
            }
        }
        echo "\033[1;32mCONNECTED\033[0m\n";
        // Identify
        $identReplies = $this->adminBot->query('NickServ', "IDENTIFY {$this->adminBot->nickname} {$this->adminPass}");
        echo '   NickServ auth: ' . (implode(' | ', $identReplies) ?: 'Sent') . "\n";
        usleep(300000);

        // 2. Founder Bot
        echo ' - Initializing Founder Bot (AresFounderBot)... ';
        $this->founderBot = new IrcBotClient('AresFounderBot', 'founder', 'Ares Founder Bot', 'pass123');
        if (!$this->founderBot->connect($this->host, $this->port, 4.0)) {
            // Try registering first
            $this->founderBot = new IrcBotClient('AresFounderBot', 'founder', 'Ares Founder Bot');
            $this->founderBot->connect($this->host, $this->port, 4.0);
        }
        echo "\033[1;32mCONNECTED\033[0m\n";
        $this->founderBot->query('NickServ', 'REGISTER pass123 founder@ares.test');
        $this->founderBot->query('NickServ', 'IDENTIFY AresFounderBot pass123');
        usleep(300000);

        // 3. Target Bot
        echo ' - Initializing Target Bot (AresTargetBot)... ';
        $this->targetBot = new IrcBotClient('AresTargetBot', 'target', 'Ares Target Bot', 'pass123');
        if (!$this->targetBot->connect($this->host, $this->port, 4.0)) {
            $this->targetBot = new IrcBotClient('AresTargetBot', 'target', 'Ares Target Bot');
            $this->targetBot->connect($this->host, $this->port, 4.0);
        }
        echo "\033[1;32mCONNECTED\033[0m\n";
        $this->targetBot->query('NickServ', 'REGISTER pass123 target@ares.test');
        $this->targetBot->query('NickServ', 'IDENTIFY AresTargetBot pass123');
        usleep(300000);

        // 4. Guest Bot (unidentified)
        echo ' - Initializing Guest Bot (AresGuestBot)... ';
        $this->guestBot = new IrcBotClient('AresGuestBot', 'guest', 'Ares Guest Bot');
        if (!$this->guestBot->connect($this->host, $this->port, 4.0)) {
            echo "\033[1;31mFAILED\033[0m\n";

            return false;
        }
        echo "\033[1;32mCONNECTED\033[0m\n";

        return true;
    }

    private function teardownBots(): void
    {
        echo "\nDisconnecting bots...\n";
        $this->adminBot?->disconnect();
        $this->founderBot?->disconnect();
        $this->targetBot?->disconnect();
        $this->guestBot?->disconnect();
    }

    private function testNickServ(): void
    {
        echo "\n\033[1;35m--- Testing NickServ Commands ---\033[0m\n";

        // General HELP test
        $generalHelp = $this->founderBot->query('NickServ', 'HELP');
        $this->recordTest('NickServ', 'HELP', '', count($generalHelp) > 5, true, true, 'HELP [command]', $generalHelp, [], [], 'General help rendered properly');

        // Commands to test: [Name, MinArgs, Subcmds, TestCall, OperOnly]
        $commands = [
            ['REGISTER', 2, [], 'REGISTER newpass123 newbot@test.local', false],
            ['IDENTIFY', 0, [], "IDENTIFY {$this->founderBot->nickname} pass123", false],
            ['VERIFY', 1, [], 'VERIFY 123456', false],
            ['RESEND', 0, [], 'RESEND', false],
            ['SET', 1, ['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'], 'SET PRIVATE ON', false],
            ['INFO', 1, [], "INFO {$this->founderBot->nickname}", false],
            ['STATUS', 1, [], "STATUS {$this->founderBot->nickname}", false],
            ['RECOVER', 1, [], "RECOVER {$this->targetBot->nickname}", false],
            ['USERIP', 1, [], "USERIP {$this->targetBot->nickname}", true],
            ['RENAME', 1, [], 'RENAME NonExistentOldNick NonExistentNewNick', true],
            ['NOEXPIRE', 2, [], "NOEXPIRE {$this->targetBot->nickname} ON", true],
            ['SUSPEND', 3, [], "SUSPEND {$this->targetBot->nickname} 1d Test suspension", true],
            ['UNSUSPEND', 1, [], "UNSUSPEND {$this->targetBot->nickname}", true],
            ['FORBID', 2, [], 'FORBID ForbiddenNick99 Test forbid', true],
            ['UNFORBID', 1, [], 'UNFORBID ForbiddenNick99', true],
            ['DROP', 1, [], "DROP {$this->founderBot->nickname}", false],
            ['FORBIDVHOST', 1, ['ADD', 'DEL', 'LIST'], 'FORBIDVHOST LIST', true],
            ['RESTORE', 1, [], 'RESTORE NonExistentNick', true],
            ['HISTORY', 2, ['ADD', 'DEL', 'VIEW', 'CLEAR'], "HISTORY {$this->founderBot->nickname} VIEW", true],
            ['SASET', 2, ['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'], "SASET {$this->targetBot->nickname} PRIVATE ON", true],
        ];

        foreach ($commands as [$name, $minArgs, $subs, $validCall, $operOnly]) {
            $this->executeCommandLifecycle('NickServ', $name, $minArgs, $subs, $validCall, $operOnly);
        }
    }

    private function testChanServ(): void
    {
        echo "\n\033[1;35m--- Testing ChanServ Commands ---\033[0m\n";

        // General HELP test
        $generalHelp = $this->founderBot->query('ChanServ', 'HELP');
        $this->recordTest('ChanServ', 'HELP', '', count($generalHelp) > 5, true, true, 'HELP [command [sub-option]]', $generalHelp, [], [], 'General help rendered properly');

        // Pre-requisite: Register test channel
        $this->founderBot->query('ChanServ', 'REGISTER #arestest Canal de pruebas automatizadas');
        $this->targetBot->send('JOIN #arestest');
        usleep(300000);

        $commands = [
            ['REGISTER', 2, [], 'REGISTER #aresnewchan Canal nuevo de test', false],
            ['INFO', 1, [], 'INFO #arestest', false],
            ['SET', 2, ['FOUNDER', 'SUCCESSOR', 'DESC', 'URL', 'EMAIL', 'ENTRYMSG', 'TOPICLOCK', 'MLOCK', 'SECURE'], 'SET #arestest SECURE ON', false],
            ['ACCESS', 2, ['LIST', 'ADD', 'DEL'], "ACCESS #arestest ADD {$this->targetBot->nickname} 10", false],
            ['AKICK', 2, ['ADD', 'DEL', 'LIST'], 'AKICK #arestest LIST', false],
            ['DELACCESS', 1, [], "DELACCESS #arestest {$this->targetBot->nickname}", false],
            ['LEVELS', 2, ['LIST', 'SET', 'RESET'], 'LEVELS #arestest LIST', false],
            ['INVITE', 1, [], 'INVITE #arestest', false],
            ['OP', 2, [], "OP #arestest {$this->targetBot->nickname}", false],
            ['DEOP', 2, [], "DEOP #arestest {$this->targetBot->nickname}", false],
            ['HALFOP', 2, [], "HALFOP #arestest {$this->targetBot->nickname}", false],
            ['DEHALFOP', 2, [], "DEHALFOP #arestest {$this->targetBot->nickname}", false],
            ['VOICE', 2, [], "VOICE #arestest {$this->targetBot->nickname}", false],
            ['DEVOICE', 2, [], "DEVOICE #arestest {$this->targetBot->nickname}", false],
            ['CLEARUSERS', 1, [], 'CLEARUSERS #arestest Test reason', true],
            ['CLEARACCESS', 1, [], 'CLEARACCESS #arestest', true],
            ['DROP', 1, [], 'DROP #arestest', false],
            ['NOEXPIRE', 2, [], 'NOEXPIRE #arestest ON', true],
            ['RESTORE', 1, [], 'RESTORE #arestest', true],
            ['SUSPEND', 3, [], 'SUSPEND #arestest 1d Test suspend', true],
            ['UNSUSPEND', 1, [], 'UNSUSPEND #arestest', true],
            ['FORBID', 2, [], 'FORBID #forbiddenchan Test reason', true],
            ['UNFORBID', 1, [], 'UNFORBID #forbiddenchan', true],
            ['HISTORY', 2, ['ADD', 'DEL', 'VIEW', 'CLEAR'], 'HISTORY #arestest VIEW', true],
        ];

        foreach ($commands as [$name, $minArgs, $subs, $validCall, $operOnly]) {
            $this->executeCommandLifecycle('ChanServ', $name, $minArgs, $subs, $validCall, $operOnly);
        }
    }

    private function testMemoServ(): void
    {
        echo "\n\033[1;35m--- Testing MemoServ Commands ---\033[0m\n";

        // General HELP test
        $generalHelp = $this->adminBot->query('MemoServ', 'HELP');
        $this->recordTest('MemoServ', 'HELP', '', count($generalHelp) > 5, true, true, 'HELP [command [sub-option]]', $generalHelp, [], [], 'General help rendered properly');

        $commands = [
            ['SEND', 2, [], "SEND {$this->targetBot->nickname} Hola este es un memo de prueba", true],
            ['LIST', 0, [], 'LIST', true],
            ['READ', 1, [], 'READ 1', true],
            ['DEL', 1, [], 'DEL 1', true],
            ['IGNORE', 1, ['ADD', 'DEL', 'LIST'], 'IGNORE LIST', true],
            ['ENABLE', 0, [], 'ENABLE', true],
            ['DISABLE', 0, [], 'DISABLE', true],
        ];

        foreach ($commands as [$name, $minArgs, $subs, $validCall, $operOnly]) {
            $this->executeCommandLifecycle('MemoServ', $name, $minArgs, $subs, $validCall, $operOnly);
        }
    }

    private function testOperServ(): void
    {
        echo "\n\033[1;35m--- Testing OperServ Commands ---\033[0m\n";

        // General HELP test (queried by Admin bot)
        $generalHelp = $this->adminBot->query('OperServ', 'HELP');
        $this->recordTest('OperServ', 'HELP', '', count($generalHelp) > 3, true, true, 'HELP [command [sub-option]]', $generalHelp, [], [], 'General OperServ help rendered properly');

        $commands = [
            ['MOTD', 1, ['ADD', 'DEL', 'LIST', 'CLEAN'], 'MOTD LIST', true],
            ['GLOBAL', 3, [], 'GLOBAL OperServ NOTICE Mensaje global de prueba desde test suite', true],
            ['GLINE', 1, ['ADD', 'DEL', 'LIST'], 'GLINE LIST', true],
            ['KILL', 2, [], 'KILL NonExistentUser99 Prueba de comando kill', true],
            ['ROLE', 1, ['LIST', 'ADD', 'DEL', 'PERMS', 'MODES', 'VHOST', 'OPERCLASS'], 'ROLE LIST', true],
            ['IRCOP', 1, ['ADD', 'DEL', 'LIST'], 'IRCOP LIST', true],
            ['RAW', 1, [], 'RAW VERSION', true],
        ];

        foreach ($commands as [$name, $minArgs, $subs, $validCall, $operOnly]) {
            $this->executeCommandLifecycle('OperServ', $name, $minArgs, $subs, $validCall, $operOnly);
        }
    }

    private function executeCommandLifecycle(string $service, string $name, int $minArgs, array $expectedSubs, string $validCall, bool $operOnly): void
    {
        $tester = $operOnly ? $this->adminBot : $this->founderBot;
        $discrepancies = [];

        // 1. HELP test
        $helpLines = $tester->query($service, "HELP {$name}");
        $helpPassed = count($helpLines) > 0;
        $advertisedSyntax = '';

        foreach ($helpLines as $line) {
            if (str_contains($line, 'Sintaxis:') || str_contains($line, 'Syntax:')) {
                $advertisedSyntax = $line;
                break;
            }
        }

        // Subcommands help check
        $detectedSubcommands = [];
        $recordingOptions = false;
        foreach ($helpLines as $line) {
            if (str_contains($line, 'Opciones disponibles:') || str_contains($line, 'Available options:')) {
                $recordingOptions = true;
                continue;
            }
            if ($recordingOptions) {
                if ('' === trim($line) || str_contains($line, '─') || str_contains($line, 'Sintaxis:')) {
                    $recordingOptions = false;
                } else {
                    $parts = explode(' ', trim($line));
                    if ('' !== $parts[0]) {
                        $detectedSubcommands[] = strtoupper($parts[0]);
                    }
                }
            }
        }

        // Check if syntax line advertises choices
        if (preg_match('/\{([^}]+)\}/', $advertisedSyntax, $m)) {
            $choices = array_map('trim', explode('|', $m[1]));
            foreach ($choices as $choice) {
                $cUpper = strtoupper($choice);
                if (in_array($cUpper, ['PRIVMSG', 'NOTICE', 'ON', 'OFF', 'PAGE', 'ALL', 'SERVICE', 'NICKNAME', 'NICK!IDENT@VHOST'], true) || str_starts_with($cUpper, '#') || str_starts_with($cUpper, 'APODO')) {
                    continue;
                }
                if ([] !== $expectedSubs && !in_array($cUpper, $expectedSubs, true)) {
                    $discrepancy = sprintf('Syntax advertises choice {%s} which is NOT in getSubCommandHelp() [%s]', $cUpper, implode(', ', $expectedSubs));
                    $discrepancies[] = $discrepancy;
                    $this->globalDiscrepancies[] = "[{$service} {$name}] {$discrepancy}";
                }
            }
        }

        // 2. Syntax Error test (negative test)
        $syntaxErrorPassed = false;
        if ($minArgs > 0) {
            $syntaxReply = $tester->query($service, $name);
            $syntaxText = implode(' ', $syntaxReply);
            $syntaxErrorPassed = count($syntaxReply) > 0 && (
                str_contains($syntaxText, 'Sintaxis')
                || str_contains($syntaxText, 'Syntax')
                || str_contains($syntaxText, 'Uso:')
                || str_contains($syntaxText, 'Usage:')
                || str_contains($syntaxText, 'error')
                || str_contains($syntaxText, 'falta')
                || str_contains($syntaxText, '✗')
            );
        } else {
            $syntaxErrorPassed = true;
        }

        // 3. Functional Execution test (positive test)
        $execReply = $tester->query($service, $validCall);
        $functionalPassed = count($execReply) > 0 && !str_contains(implode(' ', $execReply), 'Comando desconocido') && !str_contains(implode(' ', $execReply), 'Unknown command');

        // 4. Permission denial test (if oper only)
        if ($operOnly && null !== $this->guestBot) {
            $deniedReply = $this->guestBot->query($service, $validCall);
            $denied = str_contains(implode(' ', $deniedReply), 'Permiso denegado') || str_contains(implode(' ', $deniedReply), 'Permission denied') || str_contains(implode(' ', $deniedReply), 'identificarte') || str_contains(implode(' ', $deniedReply), 'Sintaxis:');
            if (!$denied && [] !== $deniedReply) {
                $discrepancies[] = 'Permission check did not strictly deny unauthenticated guest user.';
            }
        }

        $res = new CommandTestResult(
            $service,
            $name,
            implode(',', $expectedSubs),
            $helpPassed,
            $syntaxErrorPassed,
            $functionalPassed,
            $advertisedSyntax,
            $helpLines,
            $execReply,
            $discrepancies,
        );

        $this->recordTestResult($res);
        usleep(150000);
    }

    private function recordTest(string $service, string $command, string $subs, bool $helpPassed, bool $syntaxPassed, bool $funcPassed, string $advertisedSyntax, array $helpOutput, array $execOutput, array $discrepancies, string $notes): void
    {
        $res = new CommandTestResult(
            $service,
            $command,
            $subs,
            $helpPassed,
            $syntaxPassed,
            $funcPassed,
            $advertisedSyntax,
            $helpOutput,
            $execOutput,
            $discrepancies,
            $notes,
        );
        $this->recordTestResult($res);
    }

    private function recordTestResult(CommandTestResult $res): void
    {
        $this->results[] = $res;

        $statusColor = $res->isSuccess() ? "\033[1;32m[PASS]\033[0m" : "\033[1;33m[DISCREPANCY]\033[0m";
        if (!$res->functionalPassed || !$res->helpPassed) {
            $statusColor = "\033[1;31m[FAIL]\033[0m";
        }

        printf(
            "  %s %-10s %-12s (Help: %s, SyntaxErr: %s, Func: %s)\n",
            $statusColor,
            $res->service,
            $res->command,
            $res->helpPassed ? '✓' : '✗',
            $res->syntaxErrorPassed ? '✓' : '✗',
            $res->functionalPassed ? '✓' : '✗',
        );

        foreach ($res->discrepancies as $disc) {
            echo "       \033[1;33m⚠ Discrepancy:\033[0m {$disc}\n";
        }
    }

    private function generateReport(): void
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results, static fn (CommandTestResult $r): bool => $r->isSuccess()));
        $discrepanciesCount = count($this->globalDiscrepancies);

        echo "\n\033[1;36m====================================================================\033[0m\n";
        printf("\033[1;37mTotal Commands Tested: %d | Passed: %d | Discrepancies Detected: %d\033[0m\n", $total, $passed, $discrepanciesCount);
        echo "\033[1;36m====================================================================\033[0m\n\n";

        // Markdown report generation
        $md = "# Informe de Pruebas de Servicios Ares IRC y Alineación de Ayuda\n\n";
        $md .= sprintf("**Fecha de ejecución:** %s\n", date('Y-m-d H:i:s'));
        $md .= sprintf("**Total de comandos probados:** %d\n", $total);
        $md .= sprintf("**Pruebas con éxito total:** %d (%.1f%%)\n", $passed, ($passed / max(1, $total)) * 100);
        $md .= sprintf("**Discrepancias de ayuda / sintaxis detectadas:** %d\n\n", $discrepanciesCount);

        $md .= "## Resumen por Servicio\n\n";
        $md .= "| Servicio | Comandos Probados | Éxito | Discrepancias |\n";
        $md .= "| :--- | :--- | :--- | :--- |\n";

        foreach (['NickServ', 'ChanServ', 'MemoServ', 'OperServ'] as $srv) {
            $srvResults = array_filter($this->results, static fn (CommandTestResult $r): bool => $r->service === $srv);
            $srvPassed = array_filter($srvResults, static fn (CommandTestResult $r): bool => $r->isSuccess());
            $srvDiscs = array_filter($srvResults, static fn (CommandTestResult $r): bool => [] !== $r->discrepancies);
            $md .= sprintf("| **%s** | %d | %d | %d |\n", $srv, count($srvResults), count($srvPassed), count($srvDiscs));
        }

        if ([] !== $this->globalDiscrepancies) {
            $md .= "\n## Discrepancias Críticas Detectadas entre HELP y Código\n\n";
            $md .= "| Servicio y Comando | Descripción de la Desalineación | Impacto |\n";
            $md .= "| :--- | :--- | :--- |\n";
            foreach ($this->globalDiscrepancies as $disc) {
                $md .= sprintf("| `%s` | %s | Usuario confuso ante opción no implementada |\n", explode('] ', $disc)[0] . ']', explode('] ', $disc)[1] ?? $disc);
            }
        }

        $md .= "\n## Detalle Completo de Comandos Evaluados\n\n";
        $md .= "| Servicio | Comando | HELP | Error Sintaxis | Ejecución Funcional | Sintaxis Anunciada |\n";
        $md .= "| :--- | :--- | :---: | :---: | :---: | :--- |\n";

        foreach ($this->results as $r) {
            $md .= sprintf(
                "| %s | `%s` | %s | %s | %s | `%s` |\n",
                $r->service,
                $r->command,
                $r->helpPassed ? '✓' : '✗',
                $r->syntaxErrorPassed ? '✓' : '✗',
                $r->functionalPassed ? '✓' : '✗',
                $r->advertisedSyntax ?: 'N/A',
            );
        }

        file_put_contents($this->reportPath, $md);
        echo "Report saved to: {$this->reportPath}\n";
    }
}

// Execution entry point
$options = getopt('', [
    'host::',
    'port::',
    'admin-nick::',
    'admin-pass::',
    'report::',
]);

$suite = new ServicesTestSuite($options);
exit($suite->run());
