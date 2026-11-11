<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/** Marks the end of one inbound IRC line's synchronous processing window. */
final readonly class IrcMessageProcessingFinishedEvent {}
