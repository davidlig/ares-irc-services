<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\EmailVerificationSettingRepositoryInterface;
use App\NickServ\Domain\Entity\EmailVerificationSetting;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EmailVerificationSettingDoctrineRepository implements EmailVerificationSettingRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function isVerificationRequired(): bool
    {
        $setting = $this->find();

        return null === $setting || $setting->isRequired();
    }

    public function setVerificationRequired(bool $required): void
    {
        $setting = $this->find();

        if (null === $setting) {
            $this->em->persist(new EmailVerificationSetting($required));
        } else {
            $setting->change($required);
        }

        $this->em->flush();
    }

    private function find(): ?EmailVerificationSetting
    {
        return $this->em->getRepository(EmailVerificationSetting::class)->findOneBy([]);
    }
}
