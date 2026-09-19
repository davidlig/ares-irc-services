<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Messenger;

use App\Shared\Application\Port\AsyncMessageDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SymfonyAsyncMessageDispatcher implements AsyncMessageDispatcherInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {}

    public function dispatch(object $message): object
    {
        return $this->messageBus->dispatch($message);
    }
}
