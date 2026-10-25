<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

use App\OperServ\Application\Port\In\EmailDomainPolicy;
use App\OperServ\Application\Port\Out\BadmailRepository;
use App\OperServ\Domain\ValueObject\EmailDomain;
use InvalidArgumentException;

final readonly class BadmailEmailPolicy implements EmailDomainPolicy
{
    public function __construct(private BadmailRepository $repository) {}

    public function isBlocked(string $email): bool
    {
        $separator = strrpos($email, '@');
        if (false === $separator) {
            return false;
        }
        try {
            $domain = new EmailDomain(substr($email, $separator + 1));
        } catch (InvalidArgumentException) {
            // Preserve existing address validation, including domain literals.
            return false;
        }

        return $this->repository->contains($domain->value);
    }
}
