<?php

declare(strict_types=1);

namespace App\Domain\IRC\Message;

/**
 * Represents a parsed IRC message following RFC 1459 format:
 * [:prefix] COMMAND [params...] [:trailing].
 */
readonly class IRCMessage
{
    /**
     * @param string[] $params
     */
    public function __construct(
        public readonly string $command,
        public readonly ?string $prefix = null,
        public readonly array $params = [],
        public readonly ?string $trailing = null,
        public readonly MessageDirection $direction = MessageDirection::Incoming,
    ) {
    }

    public function toRawLine(): string
    {
        $parts = [];

        if (null !== $this->prefix) {
            $parts[] = ':' . $this->prefix;
        }

        $parts[] = strtoupper($this->command);

        foreach ($this->params as $param) {
            $parts[] = $param;
        }

        if (null !== $this->trailing) {
            $parts[] = ':' . $this->trailing;
        }

        return implode(' ', $parts);
    }

    public static function fromRawLine(string $rawLine): self
    {
        $rawLine = rtrim($rawLine, "\r\n");
        $prefix = null;
        $trailing = null;

        if (str_starts_with($rawLine, ':')) {
            $spacePos = strpos($rawLine, ' ');
            $prefix = substr($rawLine, 1, $spacePos - 1);
            $rawLine = substr($rawLine, $spacePos + 1);
        }

        if (false !== ($colonPos = strpos($rawLine, ' :'))) {
            $trailing = substr($rawLine, $colonPos + 2);
            $rawLine = substr($rawLine, 0, $colonPos);
        }

        $parts = explode(' ', trim($rawLine));
        $command = (string) array_shift($parts);

        return new self(
            command: $command,
            prefix: $prefix,
            params: array_values(array_filter($parts, static fn (string $p): bool => '' !== $p)),
            trailing: $trailing,
        );
    }
}
