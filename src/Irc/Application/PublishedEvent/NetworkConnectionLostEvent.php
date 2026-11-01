<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/** The active network session ended; consumers must discard session-owned state. */
final readonly class NetworkConnectionLostEvent {}
