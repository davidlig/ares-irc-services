<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\RegistrationPolicyRepository;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;

use function is_string;

/** Live queries avoid stale settings in the long-running daemon identity map. */
final readonly class DoctrineRegistrationPolicyRepository implements RegistrationPolicyRepository
{
    public function __construct(private Connection $connection) {}

    public function current(): RegistrationMode
    {
        $value = $this->connection->fetchOne('SELECT mode FROM nickserv_registration_policy WHERE id = 1');

        return is_string($value) ? RegistrationMode::from($value) : RegistrationMode::Token;
    }

    public function change(RegistrationMode $mode): RegistrationMode
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $value = $this->connection->fetchOne('SELECT mode FROM nickserv_registration_policy WHERE id = 1');
            $previous = is_string($value) ? RegistrationMode::from($value) : RegistrationMode::Token;
            if ($previous === $mode) {
                return $previous;
            }
            if (false === $value) {
                try {
                    $this->connection->insert('nickserv_registration_policy', ['id' => 1, 'mode' => $mode->value]);

                    return $previous;
                } catch (UniqueConstraintViolationException) {
                    // Another writer initialized the singleton. Re-read before changing it.
                    continue;
                }
            }
            $changed = $this->connection->executeStatement(
                'UPDATE nickserv_registration_policy SET mode = ? WHERE id = 1 AND mode = ?',
                [$mode->value, $previous->value],
            );
            if (1 === $changed) {
                return $previous;
            }
        }

        throw new RuntimeException('Registration policy changed concurrently; retry the command.');
    }
}
