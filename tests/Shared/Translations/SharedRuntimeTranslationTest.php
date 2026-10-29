<?php

declare(strict_types=1);

namespace App\Tests\Shared\Translations;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

use function hash;
use function implode;

/** Checks shared runtime message ownership and interpolation contracts. */
#[CoversNothing]
final class SharedRuntimeTranslationTest extends TestCase
{
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    #[Test]
    public function registrationErrorsUseExistingCommonTemplates(): void
    {
        $keys = ['error.channel_not_registered', 'error.nick_not_registered'];
        $parametersByKey = ['error.channel_not_registered' => ['%channel%'], 'error.nick_not_registered' => ['%nickname%']];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $template = $translator->trans($key, [], 'common', $locale);
                self::assertNotSame('', $template, $locale . ':' . $key);
                foreach ($parametersByKey[$key] as $parameter) {
                    self::assertStringContainsString($parameter, $template, $locale . ':' . $key);
                }
            }
            self::assertFalse($translator->getCatalogue($locale)->defines('restore.not_registered', 'chanserv'), $locale . ':chanserv:restore.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('drop.not_registered', 'chanserv'), $locale . ':chanserv:drop.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('suspend.not_registered', 'chanserv'), $locale . ':chanserv:suspend.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('unsuspend.not_registered', 'chanserv'), $locale . ':chanserv:unsuspend.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('noexpire.not_registered', 'chanserv'), $locale . ':chanserv:noexpire.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.not_registered', 'chanserv'), $locale . ':chanserv:history.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('send.channel_not_registered', 'memoserv'), $locale . ':memoserv:send.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('read.channel_not_registered', 'memoserv'), $locale . ':memoserv:read.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.channel_not_registered', 'memoserv'), $locale . ':memoserv:list.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('del.channel_not_registered', 'memoserv'), $locale . ':memoserv:del.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('ignore.channel_not_registered', 'memoserv'), $locale . ':memoserv:ignore.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('enable.channel_not_registered', 'memoserv'), $locale . ':memoserv:enable.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('disable.channel_not_registered', 'memoserv'), $locale . ':memoserv:disable.channel_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('recover.not_registered', 'nickserv'), $locale . ':nickserv:recover.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('identify.not_registered', 'nickserv'), $locale . ':nickserv:identify.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('info.not_registered', 'nickserv'), $locale . ':nickserv:info.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('suspend.not_registered', 'nickserv'), $locale . ':nickserv:suspend.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('unsuspend.not_registered', 'nickserv'), $locale . ':nickserv:unsuspend.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('drop.not_registered', 'nickserv'), $locale . ':nickserv:drop.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('restore.not_registered', 'nickserv'), $locale . ':nickserv:restore.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.not_registered', 'nickserv'), $locale . ':nickserv:history.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('noexpire.not_registered', 'nickserv'), $locale . ':nickserv:noexpire.not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('send.nick_not_registered', 'memoserv'), $locale . ':memoserv:send.nick_not_registered');
            self::assertFalse($translator->getCatalogue($locale)->defines('ignore.nick_not_registered', 'memoserv'), $locale . ':memoserv:ignore.nick_not_registered');
        }
    }

    #[Test]
    public function debugTemplatesUseCommonWithoutChangingOutput(): void
    {
        $keys = ['debug.action_message', 'debug.action_with_option', 'debug.action_with_value', 'debug.prefix_reason', 'debug.action_duration'];
        $expectedHashes = [
            'ca' => '744bdc79ab61d8c241f35997ef503e8e512781819c6786ab50f63af34bf6ee16',
            'de' => '9db23ad686ffd7b6306ca29c3ea6a0752c95d492e5c21daaeae5fa492f23c9d5',
            'el' => '382ee0d04a32da23f0ddac770863ea8522f2d1d5ccc6c93724830a60fd654469',
            'en' => '79397735417c3a794896f0281e86af4beb37d3ca9c86fc49aebee1a3feaabf68',
            'es' => 'f05a989218a4af9ee5e30e01ae8e6eb88a232793a0ff52434ef591de1d625e4c',
            'eu' => '4688baca0096fc5be9991a5e957a65c6ce3d09b67fdc31fc866d9d6a6932088e',
            'fr' => '23287ff1e50b155ad56468c4ab4cacfd5c99ab86f2344b161ef34eb5295cf78e',
            'gl' => '19fe337344fe09b1b1789c6b320e7d8365670c7504e11ab100f5a8a16949290e',
            'it' => 'e59796ea897ed83b72cd4afa4b5f25a523cb904de4d3d34f29417e8a331f50d0',
            'nl' => 'f25ae527cb25cb43234499e06d15b9200f212dad2642c093e4531ecaf9266a41',
            'pl' => '0cd18118f32ebaa226eb3194b5d0e1800d8e7c543a94753ddbe1eb470bb49197',
            'pt' => '4336e632fbd443b2e154dc5a6d2e47a4efecc95c2ce8fcb13f105691f65b7d04',
            'ro' => '87fdcce18e74b626a8172d5d461cd21dea2ac9e70177e8f7f39ce2766ad13c40',
            'tr' => 'ef6cc1d313b85a50a9f6198db484af94966c6f3eabd76f749a098739fa3b2566',
        ];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            $messages = [];
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $messages[] = $translator->trans($key, [], 'common', $locale);
            }
            self::assertSame($expectedHashes[$locale], hash('sha256', implode("\0", $messages)), $locale);
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_message', 'chanserv'), $locale . ':chanserv:debug.action_message');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_message', 'nickserv'), $locale . ':nickserv:debug.action_message');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_message', 'operserv'), $locale . ':operserv:debug.action_message');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_with_option', 'chanserv'), $locale . ':chanserv:debug.action_with_option');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_with_option', 'nickserv'), $locale . ':nickserv:debug.action_with_option');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_with_value', 'chanserv'), $locale . ':chanserv:debug.action_with_value');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_with_value', 'nickserv'), $locale . ':nickserv:debug.action_with_value');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.prefix_reason', 'chanserv'), $locale . ':chanserv:debug.prefix_reason');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.prefix_reason', 'nickserv'), $locale . ':nickserv:debug.prefix_reason');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.prefix_reason', 'operserv'), $locale . ':operserv:debug.prefix_reason');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.action_duration', 'nickserv'), $locale . ':nickserv:debug.action_duration');
            self::assertFalse($translator->getCatalogue($locale)->defines('debug.actionWithDuration', 'operserv'), $locale . ':operserv:debug.actionWithDuration');
        }
    }

    #[Test]
    public function historyAndListMessagesRetainTheirStoredKeysAndParameters(): void
    {
        $keys = ['history.unknown_operator', 'history.del.success', 'history.del.not_found', 'history.del.invalid_id', 'history.view.extra', 'history.message.unsuspend', 'history.extra.duration', 'history.extra.expires_at', 'history.extra.ip', 'history.extra.host', 'list.page', 'list.status_suspended', 'list.status_pending_deletion', 'list.status_forbidden'];
        $parametersByKey = ['history.del.success' => ['%id%'], 'history.del.not_found' => ['%id%'], 'history.del.invalid_id' => ['%id%'], 'history.view.extra' => ['%extra%'], 'history.extra.duration' => ['%value%'], 'history.extra.expires_at' => ['%value%'], 'history.extra.ip' => ['%value%'], 'history.extra.host' => ['%value%'], 'list.page' => ['%page%', '%pages%', '%total%']];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $template = $translator->trans($key, [], 'common', $locale);
                self::assertNotSame('', $template, $locale . ':' . $key);
                foreach ($parametersByKey[$key] ?? [] as $parameter) {
                    self::assertStringContainsString($parameter, $template, $locale . ':' . $key);
                }
            }
            self::assertFalse($translator->getCatalogue($locale)->defines('history.unknown_operator', 'chanserv'), $locale . ':chanserv:history.unknown_operator');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.unknown_operator', 'nickserv'), $locale . ':nickserv:history.unknown_operator');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.del.success', 'chanserv'), $locale . ':chanserv:history.del.success');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.del.success', 'nickserv'), $locale . ':nickserv:history.del.success');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.del.not_found', 'chanserv'), $locale . ':chanserv:history.del.not_found');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.del.not_found', 'nickserv'), $locale . ':nickserv:history.del.not_found');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.del.invalid_id', 'chanserv'), $locale . ':chanserv:history.del.invalid_id');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.del.invalid_id', 'nickserv'), $locale . ':nickserv:history.del.invalid_id');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.view.extra', 'chanserv'), $locale . ':chanserv:history.view.extra');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.view.extra', 'nickserv'), $locale . ':nickserv:history.view.extra');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.message.unsuspend', 'chanserv'), $locale . ':chanserv:history.message.unsuspend');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.message.unsuspend', 'nickserv'), $locale . ':nickserv:history.message.unsuspend');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.duration', 'chanserv'), $locale . ':chanserv:history.extra.duration');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.duration', 'nickserv'), $locale . ':nickserv:history.extra.duration');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.expires_at', 'chanserv'), $locale . ':chanserv:history.extra.expires_at');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.expires_at', 'nickserv'), $locale . ':nickserv:history.extra.expires_at');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.ip', 'chanserv'), $locale . ':chanserv:history.extra.ip');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.ip', 'nickserv'), $locale . ':nickserv:history.extra.ip');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.host', 'chanserv'), $locale . ':chanserv:history.extra.host');
            self::assertFalse($translator->getCatalogue($locale)->defines('history.extra.host', 'nickserv'), $locale . ':nickserv:history.extra.host');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.page', 'chanserv'), $locale . ':chanserv:list.page');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.page', 'nickserv'), $locale . ':nickserv:list.page');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.status_suspended', 'chanserv'), $locale . ':chanserv:list.status_suspended');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.status_suspended', 'nickserv'), $locale . ':nickserv:list.status_suspended');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.status_pending_deletion', 'chanserv'), $locale . ':chanserv:list.status_pending_deletion');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.status_pending_deletion', 'nickserv'), $locale . ':nickserv:list.status_pending_deletion');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.status_forbidden', 'chanserv'), $locale . ':chanserv:list.status_forbidden');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.status_forbidden', 'nickserv'), $locale . ':nickserv:list.status_forbidden');
        }
    }

    #[Test]
    public function neutralLabelsAndLegacySeparatorsRemainCommon(): void
    {
        $keys = ['reply.separator', 'error.nick_not_online', 'label.no_reason', 'label.never', 'label.unknown_creator', 'label.permanent', 'label.permanent_inline'];
        $parametersByKey = ['error.nick_not_online' => ['%nickname%']];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $template = $translator->trans($key, [], 'common', $locale);
                self::assertNotSame('', $template, $locale . ':' . $key);
                foreach ($parametersByKey[$key] ?? [] as $parameter) {
                    self::assertStringContainsString($parameter, $template, $locale . ':' . $key);
                }
            }
            self::assertFalse($translator->getCatalogue($locale)->defines('info.footer', 'chanserv'), $locale . ':chanserv:info.footer');
            self::assertFalse($translator->getCatalogue($locale)->defines('info.footer', 'nickserv'), $locale . ':nickserv:info.footer');
            self::assertFalse($translator->getCatalogue($locale)->defines('status.footer', 'nickserv'), $locale . ':nickserv:status.footer');
            self::assertFalse($translator->getCatalogue($locale)->defines('list.footer', 'memoserv'), $locale . ':memoserv:list.footer');
            self::assertFalse($translator->getCatalogue($locale)->defines('ignore.list_footer', 'memoserv'), $locale . ':memoserv:ignore.list_footer');
            self::assertFalse($translator->getCatalogue($locale)->defines('userip.not_online', 'nickserv'), $locale . ':nickserv:userip.not_online');
            self::assertFalse($translator->getCatalogue($locale)->defines('rename.not_online', 'nickserv'), $locale . ':nickserv:rename.not_online');
            self::assertFalse($translator->getCatalogue($locale)->defines('kill.user_not_online', 'operserv'), $locale . ':operserv:kill.user_not_online');
            self::assertFalse($translator->getCatalogue($locale)->defines('gline.user_not_found', 'operserv'), $locale . ':operserv:gline.user_not_found');
            self::assertFalse($translator->getCatalogue($locale)->defines('akick.list.no_reason', 'chanserv'), $locale . ':chanserv:akick.list.no_reason');
            self::assertFalse($translator->getCatalogue($locale)->defines('gline.list.no_reason', 'operserv'), $locale . ':operserv:gline.list.no_reason');
            self::assertFalse($translator->getCatalogue($locale)->defines('akick.list.never_expires', 'chanserv'), $locale . ':chanserv:akick.list.never_expires');
            self::assertFalse($translator->getCatalogue($locale)->defines('motd.list.never', 'operserv'), $locale . ':operserv:motd.list.never');
            self::assertFalse($translator->getCatalogue($locale)->defines('akick.list.unknown_creator', 'chanserv'), $locale . ':chanserv:akick.list.unknown_creator');
            self::assertFalse($translator->getCatalogue($locale)->defines('forbidvhost.list.unknown_creator', 'nickserv'), $locale . ':nickserv:forbidvhost.list.unknown_creator');
            self::assertFalse($translator->getCatalogue($locale)->defines('gline.list.unknown_creator', 'operserv'), $locale . ':operserv:gline.list.unknown_creator');
            self::assertFalse($translator->getCatalogue($locale)->defines('suspend.permanent', 'chanserv'), $locale . ':chanserv:suspend.permanent');
            self::assertFalse($translator->getCatalogue($locale)->defines('gline.list.never_expires', 'operserv'), $locale . ':operserv:gline.list.never_expires');
            self::assertFalse($translator->getCatalogue($locale)->defines('suspend.permanent', 'nickserv'), $locale . ':nickserv:suspend.permanent');
            self::assertFalse($translator->getCatalogue($locale)->defines('gline.permanent', 'operserv'), $locale . ':operserv:gline.permanent');
        }
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['common', 'nickserv', 'chanserv', 'memoserv', 'operserv'] as $domain) {
            $translator->addResource('yaml', __DIR__ . '/../../../translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
        }

        return $translator;
    }
}
