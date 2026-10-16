<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Fake;

use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;

/**
 * Stands in for the REGISTER use case: records the command it was handed and answers a
 * canned result, or throws when a test wants the already-registered race.
 */
final class RecordingRegisterChannelHandler implements RegisterChannelHandlerInterface
{
    /** @var list<RegisterChannel> */
    public array $received = [];

    public function __construct(
        private readonly RegisterChannelResult $result,
        private readonly ?ChannelAlreadyRegisteredException $throw = null,
    ) {}

    public function handle(RegisterChannel $command): RegisterChannelResult
    {
        $this->received[] = $command;

        if (null !== $this->throw) {
            throw $this->throw;
        }

        return $this->result;
    }
}
