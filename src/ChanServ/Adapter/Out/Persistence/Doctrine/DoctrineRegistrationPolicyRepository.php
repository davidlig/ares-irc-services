<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\RegistrationPolicyRepository;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;
use UnexpectedValueException;

use function is_string;

/** Live queries avoid stale settings in the long-running daemon identity map. */
final readonly class DoctrineRegistrationPolicyRepository implements RegistrationPolicyRepository
{
    public function __construct(private Connection $connection) {}

    public function current(): RegistrationMode
    {
        $value = $this->connection->fetchOne('SELECT setting_value FROM service_settings WHERE service_name = ? AND setting_name = ?', ['chanserv', 'registration_mode']);

        return $this->decodeMode($value);
    }

    private function decodeMode(mixed $value): RegistrationMode
    {
        if (false === $value) {
            return RegistrationMode::Direct;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException('Registration mode must be stored as JSON text.');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_string($decoded)) {
            throw new UnexpectedValueException('Registration mode must be a JSON string.');
        }

        return RegistrationMode::from($decoded);
    }

    public function change(RegistrationMode $mode): RegistrationMode
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $value = $this->connection->fetchOne('SELECT setting_value FROM service_settings WHERE service_name = ? AND setting_name = ?', ['chanserv', 'registration_mode']);
            $previous = $this->decodeMode($value);
            if ($previous === $mode) {
                return $previous;
            }
            if (false === $value) {
                try {
                    $this->connection->insert('service_settings', ['service_name' => 'chanserv', 'setting_name' => 'registration_mode', 'setting_value' => json_encode($mode->value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);

                    return $previous;
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }
            $castType = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'BINARY' : 'TEXT';
            $changed = $this->connection->executeStatement(
                'UPDATE service_settings SET setting_value = ? WHERE service_name = ? AND setting_name = ? AND CAST(setting_value AS ' . $castType . ') = ?',
                [json_encode($mode->value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'chanserv', 'registration_mode', $value],
            );
            if (1 === $changed) {
                return $previous;
            }
        }

        throw new RuntimeException('Registration policy changed concurrently; retry the command.');
    }
}
