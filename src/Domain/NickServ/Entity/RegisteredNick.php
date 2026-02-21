<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A registered IRC nickname.
 *
 * The canonical lookup key is nicknameLower (case-insensitive). The nickname
 * field preserves the original casing supplied at registration time.
 */
#[ORM\Entity]
#[ORM\Table(name: 'registered_nicks')]
#[ORM\Index(columns: ['nickname_lower'], name: 'idx_nickname_lower')]
class RegisteredNick
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /** Original casing of the nickname (display only). */
    #[ORM\Column(type: 'string', length: 32)]
    private string $nickname;

    /** Lowercase version used for case-insensitive lookups. */
    #[ORM\Column(name: 'nickname_lower', type: 'string', length: 32, unique: true)]
    private string $nicknameLower;

    /** Argon2id hash produced by password_hash(). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    /** BCP-47 language tag: 'en', 'es', etc. */
    #[ORM\Column(type: 'string', length: 10)]
    private string $language;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $lastQuitMessage = null;

    /** When true, INFO output is hidden from non-oper users. */
    #[ORM\Column(type: 'boolean')]
    private bool $private = false;

    public function __construct(
        string $nickname,
        string $passwordHash,
        string $email,
        string $language = 'en',
    ) {
        $this->nickname      = $nickname;
        $this->nicknameLower = strtolower($nickname);
        $this->passwordHash  = $passwordHash;
        $this->email         = $email;
        $this->language      = $language;
        $this->registeredAt  = new \DateTimeImmutable();
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

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function changePassword(string $newHash): void
    {
        $this->passwordHash = $newHash;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function changeEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function changeLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function markSeen(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getLastQuitMessage(): ?string
    {
        return $this->lastQuitMessage;
    }

    public function updateQuitMessage(?string $message): void
    {
        $this->lastQuitMessage = $message;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function setPrivate(bool $private): void
    {
        $this->private = $private;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }
}
