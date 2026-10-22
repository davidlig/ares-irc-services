<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\RegistrationMode;

use App\NickServ\Application\Port\Out\RegistrationPolicyRepository;

final readonly class RegistrationModeHandler implements RegistrationModeHandlerInterface
{
    public function __construct(private RegistrationPolicyRepository $repository) {}

    public function handle(RegistrationModeInput $input): RegistrationModeResult
    {
        if (!$input->authorized) {
            return new RegistrationModeResult(false);
        }
        if (null === $input->mode) {
            return new RegistrationModeResult(true, $this->repository->current());
        }
        $previous = $this->repository->change($input->mode);

        return new RegistrationModeResult(true, $input->mode, $previous !== $input->mode);
    }
}
