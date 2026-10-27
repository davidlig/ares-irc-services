<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function count;

#[CoversClass(ChannelRegistrationActivator::class)]
final class ChannelRegistrationActivatorTest extends TestCase
{
    #[Test]
    public function replacesOldLevelsAndAkicksWithRegistrationDefaults(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 7, 'description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 41);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('removeAllForChannel')->with(41);
        $levels->expects(self::exactly(count(ChannelLevel::DEFAULTS)))->method('save')->with(self::isInstanceOf(ChannelLevel::class));
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::once())->method('deleteByChannelId')->with(41);

        new ChannelRegistrationActivator($levels, $akicks)->activate($channel);
    }
}
