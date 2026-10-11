<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use function dirname;

// Deliberately do not boot Symfony Dotenv or read repository .env files.
require dirname(__DIR__, 5) . '/vendor/autoload.php';

$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = 'isolated-phpunit-dummy-secret';
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
$_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = '1';

$_ENV['KERNEL_CLASS'] = $_SERVER['KERNEL_CLASS'] = 'App\\Kernel';
$_ENV['IRC_PROTOCOL'] = $_SERVER['IRC_PROTOCOL'] = 'unrealudb';
$_ENV['NICKSERV_VHOST_SUFFIX'] = $_SERVER['NICKSERV_VHOST_SUFFIX'] = '.test';

foreach ([
    'APP_RUNTIME_OPTIONS' => '{"disable_dotenv":true}',
    'IRC_SERVER_NAME' => 'ares.test', 'IRC_IRCD_HOST' => '127.0.0.1',
    'IRC_IRCD_PORT' => '6667', 'IRC_LINK_PASSWORD' => 'isolated-dummy-link',
    'IRC_DESCRIPTION' => 'Isolated tests', 'IRC_USE_TLS' => '0',
    'IRC_SERVER_SID' => '002', 'IRC_SERVICES_VHOST' => 'ares.test',
    'IRC_UDB_BOOTSTRAP_FROM_PEER' => '0', 'SERVICES_DEFAULT_LANGUAGE' => 'en',
    'NICKSERV_GUEST_PREFIX' => 'Guest', 'NICKSERV_NICK' => 'NickServ',
    'NICKSERV_IDENT' => 'NickServ', 'NICKSERV_REALNAME' => 'NickServ',
    'CHANSERV_NICK' => 'ChanServ', 'CHANSERV_IDENT' => 'ChanServ',
    'CHANSERV_REALNAME' => 'ChanServ', 'MAINTENANCE_INTERVAL' => '60',
    'DEFAULT_URI' => 'http://localhost', 'LOCK_DSN' => 'flock',
    'MAILER_DSN' => 'null://null', 'MAILER_FROM' => 'isolated@example.invalid',
    'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
}
