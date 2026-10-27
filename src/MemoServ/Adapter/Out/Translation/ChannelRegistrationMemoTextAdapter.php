<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\Translation;

use App\MemoServ\Application\Port\Out\ChannelRegistrationMemoText;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;
use App\MemoServ\Domain\Entity\Memo;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_check_encoding;
use function mb_strcut;
use function strlen;
use function substr;

final readonly class ChannelRegistrationMemoTextAdapter implements ChannelRegistrationMemoText
{
    public function __construct(private TranslatorInterface $translator) {}

    public function render(NotifyChannelRegistrationDecision $decision, string $language): array
    {
        if (!$decision->accepted && (null === $decision->rejectionReason || '' === $decision->rejectionReason)) {
            throw new InvalidArgumentException('Rejected channel registration decision requires a reason.');
        }

        $translation = $decision->accepted ? 'registration_decision.accepted' : 'registration_decision.rejected';
        $message = $this->translator->trans($translation, [
            '%channel%' => $decision->channelName,
        ], 'memoserv', $language);

        if (!$decision->accepted) {
            $message .= ' ' . $this->translator->trans('registration_decision.reason', [
                '%reason%' => $decision->rejectionReason,
            ], 'memoserv', $language);
        }

        if (!$decision->accepted && null !== $decision->restrictedUntil) {
            $message .= ' ' . $this->translator->trans('registration_decision.restriction', [
                '%date%' => $decision->restrictedUntil->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y H:i') . ' UTC',
            ], 'memoserv', $language);
        }

        return self::splitIntoMemoMessages($message);
    }

    /** @return non-empty-list<string> */
    private static function splitIntoMemoMessages(string $message): array
    {
        if (!mb_check_encoding($message, 'UTF-8')) {
            throw new InvalidArgumentException('Rendered registration decision memo must be valid UTF-8.');
        }

        $messages = [];
        while (255 < strlen($message)) {
            $chunk = mb_strcut($message, 0, Memo::MESSAGE_MAX_LENGTH, 'UTF-8');
            $messages[] = $chunk;
            $message = substr($message, strlen($chunk));
        }

        if ('' !== $message || [] === $messages) {
            $messages[] = $message;
        }

        return $messages;
    }
}
