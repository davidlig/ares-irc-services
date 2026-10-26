<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegistrationMode;

use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\ChanServ\Application\Port\Out\RegistrationPolicyRepository;
use App\ChanServ\Domain\ValueObject\RegistrationMode;

final readonly class RegistrationModeHandler implements RegistrationModeHandlerInterface
{
    public function __construct(
        private RegistrationPolicyRepository $repository,
        private ChanAuditSink $audit,
    ) {}

    public function current(): RegistrationMode
    {
        return $this->repository->current();
    }

    public function change(RegistrationMode $mode, string $operatorNickname): RegistrationMode
    {
        $previous = $this->repository->change($mode);
        if ($previous !== $mode) {
            $this->audit->log(
                operator: $operatorNickname,
                command: 'REGMODE',
                target: 'registration-mode',
                reason: $mode->value,
                extra: ['previous' => $previous->value, 'mode' => $mode->value],
            );
        }

        return $previous;
    }
}
