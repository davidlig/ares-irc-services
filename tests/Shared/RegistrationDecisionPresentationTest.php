<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\Kernel;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function preg_replace;
use function strtolower;

#[CoversNothing]
final class RegistrationDecisionPresentationTest extends TestCase
{
    /** @var list<string> */
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    private static ?Kernel $kernel = null;

    private static ?TranslatorInterface $translator = null;

    public static function setUpBeforeClass(): void
    {
        new Dotenv()->bootEnv(dirname(__DIR__, 2) . '/.env');
        self::$kernel = new Kernel('test', true);
        self::$kernel->boot();

        $container = self::$kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $translator = $container->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::$translator = $translator;
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
        self::$translator = null;
    }

    #[Test]
    public function greekHelpKeepsClearableChannelSettingsOptional(): void
    {
        self::assertNotNull(self::$kernel);
        self::assertNotNull(self::$translator);
        $container = self::$kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $registry = $container->get(ChanServCommandRegistry::class);
        self::assertInstanceOf(ChanServCommandRegistry::class, $registry);
        $command = $registry->find('SET');
        self::assertNotNull($command);
        foreach (['EMAIL' => 'email', 'ENTRYMSG' => 'message'] as $option => $operand) {
            $validation = CommandArgumentValidator::validate($command->getArgumentDefinition(), ['#example', $option]);
            self::assertNull($validation->syntaxKey);
            self::assertStringContainsString($option . ' [' . $operand . ']', self::$translator->trans('set.syntax', [], 'chanserv', 'el'));
            self::assertStringContainsString($option . ' [' . $operand . ']', self::$translator->trans('set.' . strtolower($option) . '.syntax', [], 'chanserv', 'el'));
        }
    }

    #[Test]
    public function presentsRegistrationStatusAndDecisionMessagesWithoutPrivateOrRelativeDetails(): void
    {
        self::assertNotNull(self::$translator);

        self::assertSame(
            'CREG {PENDING [página]|ACCEPT <#canal>|REJECT <#canal> <razón>}',
            self::$translator->trans('creg.syntax', [], 'chanserv', 'es'),
        );
        self::assertSame(
            'ACCESS <#canal> {ADD <apodo> <nivel>|DEL <apodo>|LIST}',
            self::$translator->trans('access.syntax', [], 'chanserv', 'es'),
        );
        self::assertSame(
            'LEVELS <#canal> {LIST|SET <nivel> <valor>|RESET}',
            self::$translator->trans('levels.syntax', [], 'chanserv', 'es'),
        );
        self::assertStringContainsString(
            'DEL {<máscara>|<número>}',
            self::$translator->trans('akick.syntax', [], 'chanserv', 'es'),
        );
        self::assertStringContainsString(
            'TIMEZONE {<zona>|OFF}',
            self::$translator->trans('saset.syntax', [], 'nickserv', 'es'),
        );
        self::assertStringContainsString(
            'DEL {<máscara>|<número>}',
            self::$translator->trans('gline.syntax', [], 'operserv', 'es'),
        );

        $reason = self::$translator->trans('status.rejection_reason', ['%reason%' => 'canal duplicado'], 'chanserv', 'es');
        self::assertStringContainsString('Razón', $reason);
        self::assertStringContainsString('canal duplicado', $reason);

        $restriction = self::$translator->trans('status.restriction_until', ['%date%' => '28/10/2026 12:35 UTC'], 'chanserv', 'es');
        $plainRestriction = preg_replace('/\x03(?:\d{1,2}(?:,\d{1,2})?)?/', '', $restriction);
        self::assertIsString($plainRestriction);
        self::assertStringContainsString('Disponible a partir del :', $plainRestriction);
        self::assertStringContainsString('28/10/2026 12:35 UTC', $restriction);

        $memo = self::$translator->trans('registration_decision.rejected', ['%channel%' => '#example'], 'memoserv', 'es');
        self::assertStringContainsString('#example', $memo);
        self::assertStringNotContainsString('%operator%', $memo);
        self::assertStringNotContainsString('por Reviewer', $memo);
        self::assertSame(
            'Disponible a partir del: 28/10/2026 12:35 UTC',
            self::$translator->trans('registration_decision.restriction', ['%date%' => '28/10/2026 12:35 UTC'], 'memoserv', 'es'),
        );
        self::assertStringNotContainsString('%remaining_seconds%', self::$translator->trans('registration_decision.restriction', [], 'memoserv', 'en'));
    }

    #[Test]
    public function correctsDuplicateFeedbackAndRejectedDecisionColorInSpanish(): void
    {
        self::assertNotNull(self::$translator);

        self::assertSame(
            "\x0304✗\x03 El canal \x02%channel%\x02 ya está registrado o en proceso de registro.",
            self::$translator->trans('register.already_registered', ['%channel%' => '%channel%'], 'chanserv', 'es'),
        );
        self::assertStringStartsWith("\x0303✔\x03", self::$translator->trans('creg.rejected', [], 'chanserv', 'es'));
    }

    #[Test]
    public function keepsRegistrationPresentationPlaceholdersAlignedInEveryLocale(): void
    {
        self::assertNotNull(self::$translator);

        foreach (self::LOCALES as $locale) {
            $header = self::$translator->trans('status.header', ['%channel%' => '#example'], 'chanserv', $locale);
            self::assertStringContainsString('#example', $header, $locale . ' STATUS header');

            foreach (['unregistered', 'pending', 'rejected_private', 'rejected', 'registered', 'suspended', 'forbidden', 'pending_deletion'] as $state) {
                $value = self::$translator->trans('status.' . $state, [], 'chanserv', $locale);
                self::assertStringContainsString(':', $value, $locale . ' STATUS field ' . $state);
                self::assertStringNotContainsString('%channel%', $value, $locale . ' STATUS field ' . $state);
            }

            self::assertStringContainsString('%reason%', self::$translator->trans('status.rejection_reason', [], 'chanserv', $locale));
            self::assertStringContainsString('%operator%', self::$translator->trans('status.rejection_audit', [], 'chanserv', $locale));
            self::assertStringContainsString('%date%', self::$translator->trans('status.restriction_until', [], 'chanserv', $locale));

            foreach (['accepted', 'rejected'] as $decision) {
                $memo = self::$translator->trans('registration_decision.' . $decision, [], 'memoserv', $locale);
                self::assertStringContainsString('%channel%', $memo, $locale . ' decision memo ' . $decision);
                self::assertStringNotContainsString('%operator%', $memo, $locale . ' decision memo ' . $decision);
            }

            self::assertStringContainsString('%reason%', self::$translator->trans('registration_decision.reason', [], 'memoserv', $locale));
            $availability = self::$translator->trans('registration_decision.restriction', [], 'memoserv', $locale);
            self::assertStringContainsString('%date%', $availability, $locale . ' memo availability');
            self::assertStringNotContainsString('%remaining_seconds%', $availability, $locale . ' memo availability');
        }
    }
}
