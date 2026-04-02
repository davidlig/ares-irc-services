<?php

declare(strict_types=1);

namespace App\Application\OperServ\Service;

use App\Application\Port\ActiveConnectionHolderInterface;

use const STR_PAD_LEFT;

final class PseudoClientUidGenerator
{
    private int $counter = 1;

    public function __construct(
        private readonly ActiveConnectionHolderInterface $connectionHolder,
    ) {
    }

    public function generate(): ?string
    {
        $serverSid = $this->connectionHolder->getServerSid();

        if (null === $serverSid) {
            return null;
        }

        $uid = $serverSid . 'Z' . $this->base36Encode($this->counter);

        ++$this->counter;

        return $uid;
    }

    private function base36Encode(int $number): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';

        do {
            $result = $chars[$number % 36] . $result;
            $number = (int) ($number / 36);
        } while ($number > 0);

        return str_pad($result, 5, '0', STR_PAD_LEFT);
    }
}
