<?php

declare(strict_types=1);

namespace App\OperServ\Domain\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function in_array;
use function sprintf;
use function strlen;
use function strtoupper;
use function trim;

/**
 * A country blocked at the network gate.
 *
 * The country itself is resolved by the IRC daemon from the connecting IP, so
 * Ares only owns the list of blocked codes and the reason shown to the user.
 */
final class GeoipCountry
{
    public const int MAX_REASON_LENGTH = 255;

    public const int MAX_NICKNAME_LENGTH = 32;

    /**
     * Mask template of the country gate.
     *
     * The mask is an extban evaluated by the IRC daemon when a client connects,
     * which is why the block applies at connection time instead of at
     * registration time.
     */
    public const string MASK_PREFIX = '~country:';

    /** ISO 3166-1 alpha-2 officially assigned country codes. */
    private const array COUNTRY_CODES = [
        'AF', 'AX', 'AL', 'DZ', 'AS', 'AD', 'AO', 'AI', 'AQ', 'AG', 'AR', 'AM', 'AW', 'AU', 'AT', 'AZ', 'BS', 'BH', 'BD', 'BB',
        'BY', 'BE', 'BZ', 'BJ', 'BM', 'BT', 'BO', 'BQ', 'BA', 'BW', 'BV', 'BR', 'IO', 'BN', 'BG', 'BF', 'BI', 'CV', 'KH', 'CM',
        'CA', 'KY', 'CF', 'TD', 'CL', 'CN', 'CX', 'CC', 'CO', 'KM', 'CG', 'CD', 'CK', 'CR', 'CI', 'HR', 'CU', 'CW', 'CY', 'CZ',
        'DK', 'DJ', 'DM', 'DO', 'EC', 'EG', 'SV', 'GQ', 'ER', 'EE', 'SZ', 'ET', 'FK', 'FO', 'FJ', 'FI', 'FR', 'GF', 'PF', 'TF',
        'GA', 'GM', 'GE', 'DE', 'GH', 'GI', 'GR', 'GL', 'GD', 'GP', 'GU', 'GT', 'GG', 'GN', 'GW', 'GY', 'HT', 'HM', 'VA', 'HN',
        'HK', 'HU', 'IS', 'IN', 'ID', 'IR', 'IQ', 'IE', 'IM', 'IL', 'IT', 'JM', 'JP', 'JE', 'JO', 'KZ', 'KE', 'KI', 'KP', 'KR',
        'KW', 'KG', 'LA', 'LV', 'LB', 'LS', 'LR', 'LY', 'LI', 'LT', 'LU', 'MO', 'MG', 'MW', 'MY', 'MV', 'ML', 'MT', 'MH', 'MQ',
        'MR', 'MU', 'YT', 'MX', 'FM', 'MD', 'MC', 'MN', 'ME', 'MS', 'MA', 'MZ', 'MM', 'NA', 'NR', 'NP', 'NL', 'NC', 'NZ', 'NI',
        'NE', 'NG', 'NU', 'NF', 'MK', 'MP', 'NO', 'OM', 'PK', 'PW', 'PS', 'PA', 'PG', 'PY', 'PE', 'PH', 'PN', 'PL', 'PT', 'PR',
        'QA', 'RE', 'RO', 'RU', 'RW', 'BL', 'SH', 'KN', 'LC', 'MF', 'PM', 'VC', 'WS', 'SM', 'ST', 'SA', 'SN', 'RS', 'SC', 'SL',
        'SG', 'SX', 'SK', 'SI', 'SB', 'SO', 'ZA', 'GS', 'SS', 'ES', 'LK', 'SD', 'SR', 'SJ', 'SE', 'CH', 'SY', 'TW', 'TJ', 'TZ',
        'TH', 'TL', 'TG', 'TK', 'TO', 'TT', 'TN', 'TR', 'TM', 'TC', 'TV', 'UG', 'UA', 'AE', 'GB', 'US', 'UM', 'UY', 'UZ', 'VU',
        'VE', 'VN', 'VG', 'VI', 'WF', 'EH', 'YE', 'ZM', 'ZW',
    ];

    private int $id;

    private string $countryCode;

    private string $reason;

    private string $registeredByNick;

    private DateTimeImmutable $registeredAt;

    private function __construct(
        DateTimeImmutable $registeredAt,
        string $countryCode,
        string $reason,
        string $registeredByNick,
    ) {
        $this->setCountryCode($countryCode);
        $this->setReason($reason);
        $this->setRegisteredByNick($registeredByNick);
        $this->registeredAt = $registeredAt;
    }

    public static function create(
        DateTimeImmutable $registeredAt,
        string $countryCode,
        string $reason,
        string $registeredByNick,
    ): self {
        return new self($registeredAt, $countryCode, $reason, $registeredByNick);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRegisteredByNick(): string
    {
        return $this->registeredByNick;
    }

    public function getRegisteredAt(): DateTimeImmutable
    {
        return $this->registeredAt;
    }

    /**
     * Mask that blocks this country at connection time.
     */
    public function mask(): string
    {
        return self::maskFor($this->countryCode);
    }

    /**
     * Normalizes a country code to its canonical uppercase representation.
     */
    public static function normalizeCode(string $countryCode): string
    {
        return strtoupper(trim($countryCode));
    }

    /**
     * Tells whether the code is an assigned ISO 3166-1 alpha-2 country code.
     */
    public static function isKnownCode(string $countryCode): bool
    {
        return in_array(self::normalizeCode($countryCode), self::COUNTRY_CODES, true);
    }

    /**
     * Mask that blocks the given country code at connection time.
     */
    public static function maskFor(string $countryCode): string
    {
        return self::MASK_PREFIX . self::normalizeCode($countryCode);
    }

    private function setCountryCode(string $countryCode): void
    {
        $code = self::normalizeCode($countryCode);
        if (!self::isKnownCode($code)) {
            throw new InvalidArgumentException(sprintf('Unknown ISO 3166-1 alpha-2 country code "%s".', $countryCode));
        }

        $this->countryCode = $code;
    }

    private function setReason(string $reason): void
    {
        if ('' === $reason) {
            throw new InvalidArgumentException('A blocked country requires a reason.');
        }

        if (strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf('Reason cannot exceed %d characters.', self::MAX_REASON_LENGTH));
        }

        $this->reason = $reason;
    }

    private function setRegisteredByNick(string $registeredByNick): void
    {
        if ('' === $registeredByNick) {
            throw new InvalidArgumentException('A blocked country requires the registering nickname.');
        }

        if (strlen($registeredByNick) > self::MAX_NICKNAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('Nickname cannot exceed %d characters.', self::MAX_NICKNAME_LENGTH));
        }

        $this->registeredByNick = $registeredByNick;
    }
}
