<?php

declare(strict_types=1);

/**
 * Ares IRC Services.
 *
 * Copyright (c) 2026 David Abuín Fontán <https://ares-services.davidlig.net>
 *
 * This file is part of Ares IRC Services and is licensed under the
 * GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).
 * Pursuant to Section 7(b) of the GNU AGPLv3, additional terms apply
 * regarding the preservation of original author attribution.
 * See ADDITIONAL_TERMS.md for details.
 */

namespace App\Irc\Adapter\In\Event;

use App\Irc\Application\Port\Out\ServiceUserPreferences;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function explode;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strtoupper;
use function trim;

final readonly class CtcpVersionResponder
{
    private const string AUTHOR = 'David Abuín Fontán';

    private const string WEBSITE = 'https://ares-services.davidlig.net';

    private const array ASCII_ART = [
        '                                                     ..                         ',
        '                                                  ..::......::...........       ',
        '                                                :.....     .:.:.  ..........    ',
        '                       ..:-:.:.:------:-:::::---=-----:      .:::. ........:.   ',
        '                     :::::..:--=-=------=::--:.::--:-===-.    .:::.  ..... ..:  ',
        '              .......::..  ::--------::=-::::-::::::--=====:    ::........ ..:  ',
        '         ....:..    ..    ::--:-----::---.-::--::-----=======:  .::..........   ',
        '       ........    ..   .::::-::------=--:::::-------==========. ::.........    ',
        '     .:......     .:  .::::-:::========-:::::.:--=---:--=+++====:.::....::..    ',
        '    ..... ...     .. .:.:::---=-:---=++-:::::::--::.  .:::---==+=:.:::::....    ',
        '     ... ...     .. .:::::::::......:===:--.:..:-:.       .:::-=+=..:::::.      ',
        '     ......     ... ..::::......    .:--::-:.. ...     ...   .::==: .::.        ',
        '     ......     .  .:...........      .. ::....      .:...--=..:=--:.:.         ',
        '       .....   ..  .....    ..:...      ....... ..  .:. .   .:::==-: ..         ',
        '        ...... .   ....         .     .................::::::::-===-.           ',
        '        . ...... ....::.  .     ..    ..........:::..:......::-=+==-.           ',
        '                   ..:::.   ...        ..  ..... :::...:...:--=++===:           ',
        '           .     ...:::-:......        .::  :.:  .::....::::-=+++===:           ',
        '                ....:::---... .    .        : :.  .::::---:--=+++===:           ',
        '                .:..:::---:. .. .:::......... :..:::::-=------=++==-.           ',
        '              .....::.::::..... ..::..... ..:.:.::..:::-=--:-===+==-            ',
        '               ... .:.::::....  .:::........:.:.::..:::-----:-=+===.            ',
        '                .  .:.::::.... ....::.... ... ..:::..:--:::-:--===-             ',
        '              . ..  .:::....   .  .:::.... .   ..::.::---:.::::-:-:.            ',
        '                ..  ..::..       .::::.....      ...:::-----::.::-:.            ',
        '               . ..  .::.       ..::::....        ..:-==-:::::::-::             ',
        '               :....  ...         ..::... ...     ..:-------:-:-:.              ',
        '                   .. ...       ....:::. ..::........:-====--.--:               ',
        '                 .  .. ...      ...:.::..::--:.:---:..:-==+-.::.                ',
        '                   .  ....      .:.::. :--====-===++==-.:=-.::.                 ',
        '                       ....      .. . :--===++-==+++*++...:..:.                 ',
        '                           .       .. :-===+++===+=++*:=:::.:::.                ',
        '                     ..     ...     .. -==++=+====+=++.-==:::::                 ',
        '                       ..   .::.   .:..=+++==+==+====+.-=----:.                 ',
        '                         ..   ::. ...   .===========::-::--::.                  ',
        '                     .....::... ..... .... .::..:..:=-=:.                       ',
        '                   ... ..             .:::::..::::-=:..                         ',
        '                   ...                 .. :::.    ....                          ',
        '                                       .:.   ..                                 ',
        '                      .      .     @@@  @                                       ',
    ];

    private const string SIGNATURE = "\x034\u{2764}\x0F Ares 2011-2023 \x034\u{2764}\x0F";

    public function __construct(
        private TranslatorInterface $translator,
        private ServiceUserPreferences $languageResolver,
        private string $servicesVersion,
        private string $tributeMode = 'FULL',
    ) {}

    public function getLanguageResolver(): ServiceUserPreferences
    {
        return $this->languageResolver;
    }

    public function getVersionResponse(): string
    {
        return sprintf(
            'Ares IRC Services %s - %s | %s',
            $this->servicesVersion,
            self::AUTHOR,
            self::WEBSITE,
        );
    }

    /**
     * @return string[]
     */
    public function getAsciiArtLines(string $language): array
    {
        $tribute = $this->translator->trans(
            'ctcp.version.tribute',
            [],
            'common',
            $language,
        );

        $tribute = $this->unescapeIrcCodes($tribute);
        $tributeLines = explode("\n", $tribute);

        $tributeLines = array_map(
            static fn (string $line): string => '' === $line ? ' ' : $line,
            $tributeLines,
        );

        $signatureLine = str_repeat(' ', 30) . self::SIGNATURE;

        if ('MINIMAL' === strtoupper(trim($this->tributeMode))) {
            return [
                ...$tributeLines,
                ' ',
                $signatureLine,
            ];
        }

        return [
            ...self::ASCII_ART,
            ' ', ' ', ' ',
            ...$tributeLines,
            ' ',
            $signatureLine,
        ];
    }

    private function unescapeIrcCodes(string $text): string
    {
        return str_replace(
            ['\x02', '\x03', '\x0F'],
            ["\x02", "\x03", "\x0F"],
            $text,
        );
    }
}
