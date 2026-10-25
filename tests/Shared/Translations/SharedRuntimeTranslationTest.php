<?php

declare(strict_types=1);

namespace App\Tests\Shared\Translations;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

/** Pins decoded runtime messages from before extraction, including IRC controls. */
#[CoversNothing]
final class SharedRuntimeTranslationTest extends TestCase
{
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    private static function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['common', 'nickserv', 'chanserv', 'memoserv', 'operserv'] as $domain) {
            $translator->addResource('yaml', __DIR__ . '/../../../translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
        }

        return $translator;
    }

    #[Test]
    public function registrationErrorsUseExistingCommonMessages(): void
    {
        $keys = ['error.channel_not_registered', 'error.nick_not_registered'];
        $expectedHashes = [
            'ca' => '1968debab3776245d74bce864d4f5db5362abe395423e96e7ea85fa455e8c9d6',
            'de' => '588cdcb0962f0334b62f46e76e8a44fe5c4b568b32ed794690ff45a28ba659a3',
            'el' => 'c6fe32ec07bc642ecc200f11f1d183a410f01e5edfacb2daf3810968b14f38c6',
            'en' => 'bfb6c8533c93c45d53e2f38569558ccc620683f20e14d3793606d951f0d4c46d',
            'es' => 'df1b4d9922c93d4bdab68d204d8e8ef497cb5f8b5b78c8b7501c48052c46005c',
            'eu' => 'd4fbc5c4ff29d693508b2d453337149a92ab650d3416e27a5b8dbd52515fb166',
            'fr' => '16c62834fddd2bcb2752128a18e2046e4f71158a7e6cc1b1f76920868f62f662',
            'gl' => 'd25baf4a59355dd412790b6d79084620b3ee4cc8e25fd0a2853b6f53f4f2a981',
            'it' => 'f5488fd391bb94efa7e9283cb215e20845c285797c881626b2075375c448f1d8',
            'nl' => '33dd95d331455121f426a6e5c2621895020a1ab94b950a3b8c9faf511808a44b',
            'pl' => '414bbe52012a4970bc46b1bb2ed9f91d13347256d7a4ea1fcca4696d83558623',
            'pt' => '0db7b4b37a0855bb068b18293a623ffcf92b5b41dd8568c2fb2eee17858d2dc7',
            'ro' => '4de8046a394ce591dc191bf140293ccdedc31072f2537e1e5a03b23243e9614f',
            'tr' => '61a4f5c944bbf969f5a91b71c5b43a4cf5155ff2016934a129e1d34c168c7d10',
        ];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            $messages = [];
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $messages[] = $translator->trans($key, [], 'common', $locale);
            }
            self::assertSame($expectedHashes[$locale], hash('sha256', implode("\0", $messages)), $locale);
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
    public function historyAndListMessagesRetainTheirStoredKeysAndOutput(): void
    {
        $keys = ['history.unknown_operator', 'history.del.success', 'history.del.not_found', 'history.del.invalid_id', 'history.view.extra', 'history.message.unsuspend', 'history.extra.duration', 'history.extra.expires_at', 'history.extra.ip', 'history.extra.host', 'list.page', 'list.status_suspended', 'list.status_pending_deletion', 'list.status_forbidden'];
        $expectedHashes = [
            'ca' => '30744cb450d8c32c261d20e5785633de9f2613024ebf3e9f8e28f97520bf26c1',
            'de' => 'e7bf4e6b0f06096b697ee2e135402131aedfcfb674521c28dc7cd1d1be902f11',
            'el' => '82f9162fb127e5ca3d2ccd0509dd54646762c04dbea6c1d4febd89937bd5a870',
            'en' => 'fbc80c091da3ec7d668043b3c6a908fb14aeae1cfb4419f2a92880b69e987ada',
            'es' => '74e1ca7d83fc785480c46b391c57e974c5ce1dcf880d9d4e465135a11bafaa53',
            'eu' => '4aaec93a626516fe3c5252b85e0448ffc49da23656e89c55ef0af16dae66caf1',
            'fr' => 'f0d29ba569fcdb7b49fc38151a941f7b05aa209f0516d453a80a68d123a5ef6a',
            'gl' => 'b4ac65c61807859c2113bc3dfedb699b4eaa74bd339e95fcbbc31f44316d25ec',
            'it' => '0fdb861b8154a54d9c9b3537fa27810f9046a87045e49d9005057d1ef58746eb',
            'nl' => 'fee46918ffd359206530dfcce0451b58e0dcaed07098b8dda6a19ca71e7563bf',
            'pl' => '7b8d4c015bbad1dadf403188b415baa82bbc27c6107c3a5e724dc6d7372c80eb',
            'pt' => 'd6fe526d427f8c904289c1b64800bb90ada8d3429fa315da197d00489f85829f',
            'ro' => '634c58343a6350f464c9328dd552f857cedac05c0f87b1b0175a9ca751771677',
            'tr' => '6bc6d58eb3bf3ad24a94887d11649c39f3a9cc7935e6c9178cdf3d73352cf150',
        ];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            $messages = [];
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $messages[] = $translator->trans($key, [], 'common', $locale);
            }
            self::assertSame($expectedHashes[$locale], hash('sha256', implode("\0", $messages)), $locale);
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
    public function neutralLabelsAndSeparatorsPreserveTheirExactOutput(): void
    {
        $keys = ['reply.separator', 'error.nick_not_online', 'label.no_reason', 'label.never', 'label.unknown_creator', 'label.permanent', 'label.permanent_inline'];
        $expectedHashes = [
            'ca' => 'dbba2d20cf7cc1bfad668a9d4672fdbaee353dff524eb130cb57bb51dd5b0829',
            'de' => 'bb5c7b944d631ce972a093da3dae916932ffe36eb23daa05fa32607db1d1324f',
            'el' => 'da004a0248d28724e72d451d7652b70d9d110a8fa27e6da0ab1e157163034d51',
            'en' => 'be6e37cf26331c24b4332f0efd5f08b0e5d69190abc05de074b24f316b8fe871',
            'es' => '18dcc1e881233f9888a9c89a8d706aaf2d5e25501d57eeafc1e2deeeb9c034bf',
            'eu' => '0159c29a89f0433c3d18183f32ad620cafda04a6721f2432d541288ece7a443b',
            'fr' => '1a4ec7db9fe0cc34f4ef6f156933bfd79c4ee3e60f415708996650e35ee65752',
            'gl' => 'd0ce60f10e67946d84561beab84bf737e845f70cf7d556c89c948fed63b1f68d',
            'it' => '1c46f4a57c54852e153d933dc2c74789d3a30d01f4594dc294b6c1745cf04269',
            'nl' => 'e49ea512bc18517ae059096a23f0c59cf8822bd23019be69c51f134b56bc7a17',
            'pl' => '1cd1c0cd1825f1846d9f5f36528d21e07e2372f47955c19fcae18c7efae064f5',
            'pt' => 'f85185be9b28c458713d9bfc751fc87aec67a6e4fbb144a65fe75628640bffa3',
            'ro' => '0fc6d1876c233bda19b87764c86ed3c446beabbe085b5c5bd0549e5e713d4f71',
            'tr' => '022eb37124b042ecd9a3c3e3f3242957446990dc0399f60778e39b8b1d9d7836',
        ];

        foreach (self::LOCALES as $locale) {
            $translator = self::translator($locale);
            $messages = [];
            foreach ($keys as $key) {
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'common'), $locale . ':' . $key);
                $messages[] = $translator->trans($key, [], 'common', $locale);
            }
            self::assertSame($expectedHashes[$locale], hash('sha256', implode("\0", $messages)), $locale);
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
}
