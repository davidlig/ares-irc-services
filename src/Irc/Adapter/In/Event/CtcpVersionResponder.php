<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Application\Port\Out\ServiceUserPreferences;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CtcpVersionResponder
{
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
    ) {}

    public function getLanguageResolver(): ServiceUserPreferences
    {
        return $this->languageResolver;
    }

    public function getVersionResponse(): string
    {
        return 'Ares IRC Services ' . $this->servicesVersion;
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
