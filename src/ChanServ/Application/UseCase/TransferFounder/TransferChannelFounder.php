<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use SensitiveParameter;

final readonly class TransferChannelFounder
{
    public function __construct(
        public RegisteredChannel $channel,
        public string $targetNickname,
        #[SensitiveParameter]
        public ?string $token,
        public ?FounderTransferActor $actor,
        public bool $founderEquivalent,
        public string $serviceNickname,
        public string $locale,
        public DateTimeImmutable $requestedAt,
    ) {}
}
