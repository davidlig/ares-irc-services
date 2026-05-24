#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Adds missing methods to Domain entities after asymmetric visibility conversion.
 */

// Simple string-based approach for reliability
$fixes = [
    'src/Domain/IRC/Network/NetworkUser.php' => [
        'needle' => 'public function getNick(): Nick',
        'insert' => '

    public function getDisplayHost(): string
    {
        return $this->displayHost;
    }',
    ],
    'src/Domain/ChanServ/Entity/RegisteredChannel.php' => [
        'needle' => 'public function isNoExpire(): bool',
        'insert' => '

    public function setNoExpire(bool $noExpire): void
    {
        $this->noExpire = $noExpire;
    }',
    ],
    'src/Domain/NickServ/Entity/RegisteredNick.php' => [
        'needle' => 'public function isNoExpire(): bool',
        'insert' => '

    public function setNoExpire(bool $noExpire): void
    {
        $this->noExpire = $noExpire;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }',
    ],
    'src/Domain/OperServ/Entity/OperRole.php' => [
        'needle' => 'public function isProtected(): bool',
        'insert' => '

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setForcedVhostPattern(?string $pattern): void
    {
        $this->forcedVhostPattern = $pattern;
    }',
    ],
    'src/Domain/OperServ/Entity/OperIrcop.php' => [
        'needle' => 'public function getReason(): ?string',
        'insert' => '

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }',
    ],
    'src/Domain/OperServ/Entity/OperPermission.php' => [
        'needle' => 'public function getDescription(): string',
        'insert' => '

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }',
    ],
    'src/Domain/OperServ/Entity/Gline.php' => [
        'needle' => 'public function getCreatedAt(): DateTimeImmutable',
        'insert' => '

    public function updateExpiry(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function updateReason(?string $reason): void
    {
        $this->reason = $reason;
    }',
    ],
    'src/Domain/ChanServ/Entity/ChannelAkick.php' => [
        'needle' => 'public function getCreatedAt(): DateTimeImmutable',
        'insert' => '

    public function updateExpiry(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }',
    ],
];

$baseDir = __DIR__ . '/../';

foreach ($fixes as $relativePath => $fix) {
    $filePath = $baseDir . $relativePath;
    if (!file_exists($filePath)) {
        echo "SKIP: {$filePath} not found\n";
        continue;
    }

    $content = file_get_contents($filePath);
    if (!str_contains($content, $fix['needle'])) {
        echo "SKIP: needle not found in {$relativePath}\n";
        continue;
    }

    $newContent = str_replace(
        $fix['needle'],
        $fix['needle'] . $fix['insert'],
        $content,
    );

    file_put_contents($filePath, $newContent);

    // Verify syntax
    exec("php -l {$filePath} 2>&1", $out, $ret);
    if (0 === $ret) {
        echo "OK: {$relativePath}\n";
    } else {
        echo "ERROR: {$relativePath}\n";
        foreach ($out as $line) {
            echo "  {$line}\n";
        }
    }
}
