<?php

declare(strict_types=1);

namespace App\Application\ApplicationPort;

use App\Application\Port\ActiveConnectionHolderInterface;

use function str_pad;

use const STR_PAD_LEFT;

class ServiceUidGenerator implements ServiceUidGeneratorInterface
{
    private int $counter = 0;

    /** @var array<string, string> */
    private array $generated = [];

    public function __construct(
        private readonly ActiveConnectionHolderInterface $connectionHolder,
    ) {
    }

    public function generateUid(string $serviceKey): string
    {
        if (isset($this->generated[$serviceKey])) {
            return $this->generated[$serviceKey];
        }

        $serverSid = $this->connectionHolder->getServerSid() ?? '000';

        $letter = $this->getServiceLetter($serviceKey);

        ++$this->counter;
        $suffix = str_pad($this->base36Encode($this->counter), 5, '0', STR_PAD_LEFT);

        $uid = $serverSid . $letter . $suffix;
        $this->generated[$serviceKey] = $uid;

        return $uid;
    }

    private function getServiceLetter(string $serviceKey): string
    {
        return match ($serviceKey) {
            'nickserv' => 'A',
            'chanserv' => 'B',
            'memoserv' => 'C',
            'operserv' => 'E',
            default => 'Z',
        };
    }

    private function base36Encode(int $number): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';

        do {
            $result = $chars[$number % 36] . $result;
            $number = (int) ($number / 36);
        } while ($number > 0);

        return $result;
    }
}
