<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Protocol;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\NickServ\Application\Port\Out\NicknamePolicyInterface;

use function min;
use function preg_match;
use function strlen;

final readonly class ProtocolNicknamePolicy implements NicknamePolicyInterface
{
    private const string SYNTAX_PATTERN = '/^[A-Za-z\[\]\\\\`_^{|}][A-Za-z0-9\[\]\\\\`_^{|}\-]*$/';

    public function __construct(
        private ActiveProtocolModuleHolderInterface $connectionHolder,
        private int $doctrineColumnCapacity = 32,
        private int $fallbackIrcdMaxNickLen = 30,
    ) {}

    public function isValid(string $nickname): bool
    {
        if (1 !== preg_match(self::SYNTAX_PATTERN, $nickname)) {
            return false;
        }

        return strlen($nickname) <= $this->getMaximumLength();
    }

    public function getMaximumLength(): int
    {
        $ircdMax = $this->connectionHolder->getProtocolModule()?->getMaxNickLength() ?? $this->fallbackIrcdMaxNickLen;

        return min($this->doctrineColumnCapacity, $ircdMax);
    }
}
