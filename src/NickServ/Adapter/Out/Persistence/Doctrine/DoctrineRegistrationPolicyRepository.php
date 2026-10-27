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
        $value = $this->connection->fetchOne('SELECT setting_value FROM service_settings WHERE service_name = ? AND setting_name = ?', ['nickserv', 'registration_mode']);

        return is_string($value) ? RegistrationMode::from($value) : RegistrationMode::Token;
    }

    public function change(RegistrationMode $mode): RegistrationMode
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $value = $this->connection->fetchOne('SELECT setting_value FROM service_settings WHERE service_name = ? AND setting_name = ?', ['nickserv', 'registration_mode']);
            $previous = is_string($value) ? RegistrationMode::from($value) : RegistrationMode::Token;
            if ($previous === $mode) {
                return $previous;
            }
            if (false === $value) {
                try {
                    $this->connection->insert('service_settings', ['service_name' => 'nickserv', 'setting_name' => 'registration_mode', 'setting_value' => $mode->value]);

                    return $previous;
                } catch (UniqueConstraintViolationException) {
                    // Another writer initialized this setting. Re-read before changing it.
                    continue;
                }
            }
            $changed = $this->connection->executeStatement(
                'UPDATE service_settings SET setting_value = ? WHERE service_name = ? AND setting_name = ? AND setting_value = ?',
                [$mode->value, 'nickserv', 'registration_mode', $previous->value],
            );
            if (1 === $changed) {
                return $previous;
            }
        }

        throw new RuntimeException('Registration policy changed concurrently; retry the command.');
    }
}
