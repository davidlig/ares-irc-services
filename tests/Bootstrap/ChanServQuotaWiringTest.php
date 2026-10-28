<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandler;
use App\ChanServ\Application\UseCase\TransferFounder\TransferChannelFounderHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class ChanServQuotaWiringTest extends TestCase
{
    #[Test]
    public function registrationApprovalAndTransferUseTheSameConfiguredMaximum(): void
    {
        $config = Yaml::parseFile(__DIR__ . '/../../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        $services = $config['services'];
        self::assertIsArray($services);
        foreach ([RegisterChannelHandler::class, ReviewRegistrationHandler::class, TransferChannelFounderHandler::class] as $handler) {
            $definition = $services[$handler];
            self::assertIsArray($definition);
            $arguments = $definition['arguments'];
            self::assertIsArray($arguments);
            self::assertSame('%chanserv.max_channels_per_nick%', $arguments['$maxChannelsPerNick']);
        }
        $parameters = $config['parameters'];
        self::assertIsArray($parameters);
        self::assertSame(3, $parameters['chanserv.max_channels_per_nick_default']);
        self::assertSame('%env(int:default:chanserv.max_channels_per_nick_default:CHANSERV_MAX_CHANNELS_PER_NICK)%', $parameters['chanserv.max_channels_per_nick']);
    }
}
