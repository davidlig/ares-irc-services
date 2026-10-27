<?php

declare(strict_types=1);

namespace App\OperServ\Domain\Entity;

use App\OperServ\Domain\ValueObject\EmailDomain;
use InvalidArgumentException;

use function preg_match;
use function trim;

/** Persisted by Doctrine, which supplies its generated identifier. */
class BadmailEntry
{
    private int $id;

    private string $domain;

    private string $reason;

    public function __construct(string $domain, string $reason)
    {
        $this->domain = new EmailDomain($domain)->value;
        $reason = trim($reason);
        if ('' === $reason || 1 === preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InvalidArgumentException('A nonblank, single-line administrative reason is required.');
        }
        $this->reason = $reason;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
