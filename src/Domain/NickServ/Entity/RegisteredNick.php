<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Entity;

use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use InvalidArgumentException;

use function in_array;
use function sprintf;

use const FILTER_VALIDATE_EMAIL;

/**
 * A registered (or pending/suspended/forbidden) IRC nickname.
 *
 * The canonical lookup key is nicknameLower (case-insensitive). The nickname
 * field preserves the original casing supplied at registration time.
 *
 * passwordHash, email and registeredAt are nullable because FORBIDDEN entries
 * have no owner; all other statuses always carry non-null values for these fields.
 *
 * Persistence mapping is defined in config/doctrine (XML); Domain has no Doctrine dependency.
 */
class RegisteredNick
{
    /** Supported BCP-47 language tags for account preferences. */
    public const array SUPPORTED_LANGUAGES = ['en', 'es'];

    private int $id;

    /** Original casing of the nickname (display only). */
    private string $nickname;

    /** Lowercase version used for case-insensitive lookups. */
    private string $nicknameLower;

    private NickStatus $status;

    /** Argon2id hash produced by password_hash(). Null for FORBIDDEN entries. */
    private ?string $passwordHash;

    /** Null for FORBIDDEN entries. One email per account (unique across nicks). */
    private ?string $email {
        set(?string $value) {
            if (null !== $value && false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid email address.');
            }
            $this->email = $value;
        }
    }

    /** BCP-47 language tag: 'en', 'es', etc. */
    private string $language {
        set(string $value) {
            $lang = strtolower($value);
            if (!in_array($lang, self::SUPPORTED_LANGUAGES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported language "%s". Supported: %s.', $value, implode(', ', self::SUPPORTED_LANGUAGES)));
            }
            $this->language = $lang;
        }
    }

    /** Null for FORBIDDEN entries. */
    private ?DateTimeImmutable $registeredAt;

    /** Only set while status is PENDING; null once the account is activated. */
    private ?DateTimeImmutable $expiresAt = null;

    /** Human-readable reason for SUSPENDED or FORBIDDEN status. */
    private ?string $reason = null;

    private ?DateTimeImmutable $lastSeenAt = null;

    private ?string $lastQuitMessage = null;

    /** When true, INFO output is hidden from non-oper users. */
    private bool $private = false;

    /** Custom virtual host (displayed hostname). Null = use default/cloak. */
    private ?string $vhost = null;

    /**
     * Creates a new pending registration awaiting email verification.
     */
    public static function createPending(
        string $nickname,
        string $passwordHash,
        string $email,
        string $language,
        DateTimeImmutable $expiresAt,
    ): self {
        $nick = new self();
        $nick->nickname = $nickname;
        $nick->nicknameLower = strtolower($nickname);
        $nick->status = NickStatus::Pending;
        $nick->passwordHash = $passwordHash;
        $nick->email = $email;
        $nick->language = $language;
        $nick->registeredAt = new DateTimeImmutable();
        $nick->expiresAt = $expiresAt;

        return $nick;
    }

    /**
     * Creates a permanently forbidden nick entry (no owner).
     */
    public static function createForbidden(
        string $nickname,
        string $reason,
        string $language = 'en',
    ): self {
        $nick = new self();
        $nick->nickname = $nickname;
        $nick->nicknameLower = strtolower($nickname);
        $nick->status = NickStatus::Forbidden;
        $nick->passwordHash = null;
        $nick->email = null;
        $nick->language = $language;
        $nick->registeredAt = null;
        $nick->reason = $reason;

        return $nick;
    }

    /**
     * Transitions the account from PENDING to REGISTERED after email verification.
     */
    public function activate(): void
    {
        $this->status = NickStatus::Registered;
        $this->expiresAt = null;
    }

    /**
     * Suspends the account with an optional reason.
     */
    public function suspend(string $reason): void
    {
        $this->status = NickStatus::Suspended;
        $this->reason = $reason;
    }

    /**
     * Lifts the suspension and restores the account to REGISTERED.
     */
    public function unsuspend(): void
    {
        $this->status = NickStatus::Registered;
        $this->reason = null;
    }

    public function isPending(): bool
    {
        return NickStatus::Pending === $this->status;
    }

    public function isRegistered(): bool
    {
        return NickStatus::Registered === $this->status;
    }

    public function isSuspended(): bool
    {
        return NickStatus::Suspended === $this->status;
    }

    public function isForbidden(): bool
    {
        return NickStatus::Forbidden === $this->status;
    }

    public function isExpired(): bool
    {
        return NickStatus::Pending === $this->status
            && null !== $this->expiresAt
            && $this->expiresAt < new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNickname(): string
    {
        return $this->nickname;
    }

    public function getNicknameLower(): string
    {
        return $this->nicknameLower;
    }

    public function getStatus(): NickStatus
    {
        return $this->status;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getRegisteredAt(): ?DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getLastQuitMessage(): ?string
    {
        return $this->lastQuitMessage;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function getVhost(): ?string
    {
        return $this->vhost;
    }

    public function changePassword(string $newHash): void
    {
        $this->passwordHash = $newHash;
    }

    /**
     * Changes the password by hashing the plain password via the given hasher.
     * Use this from Application; the entity does not depend on a specific algorithm.
     */
    public function changePasswordWithHasher(string $plainPassword, PasswordHasherInterface $hasher): void
    {
        $this->passwordHash = $hasher->hash($plainPassword);
    }

    public function changeEmail(string $email): void
    {
        $this->email = $email;
    }

    public function changeLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function markSeen(): void
    {
        $this->lastSeenAt = new DateTimeImmutable();
    }

    public function updateQuitMessage(?string $message): void
    {
        $this->lastQuitMessage = $message;
    }

    public function switchPrivate(bool $private): void
    {
        $this->private = $private;
    }

    /**
     * Set or clear the account's custom virtual host.
     * Null clears the vhost (user will show default/cloak).
     */
    public function changeVhost(?string $vhost): void
    {
        $this->vhost = $vhost;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        if (null === $this->passwordHash) {
            return false;
        }

        return password_verify($plainPassword, $this->passwordHash);
    }
}
