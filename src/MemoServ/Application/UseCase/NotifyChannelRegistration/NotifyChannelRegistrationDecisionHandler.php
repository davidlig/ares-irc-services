<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\NotifyChannelRegistration;

use App\MemoServ\Application\Port\Out\ChannelRegistrationMemoText;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\Port\Out\ServiceMemoSender;
use App\MemoServ\Domain\Entity\Memo;
use InvalidArgumentException;

use function mb_check_encoding;
use function strlen;

final readonly class NotifyChannelRegistrationDecisionHandler implements NotifyChannelRegistrationDecisionHandlerInterface
{
    public function __construct(
        private MemoUserAccountPort $userAccountPort,
        private MemoRepositoryInterface $memoRepository,
        private ChannelRegistrationMemoText $memoText,
        private ServiceMemoSender $serviceMemoSender,
    ) {}

    public function handle(NotifyChannelRegistrationDecision $decision): void
    {
        if (null === $this->userAccountPort->findNicknameById($decision->requesterAccountId)) {
            return;
        }

        $language = $this->userAccountPort->getLanguage($decision->requesterAccountId);
        $senderName = $this->serviceMemoSender->getSenderName();
        $messages = $this->memoText->render($decision, $language);
        foreach ($messages as $message) {
            if (255 < strlen($message) || !mb_check_encoding($message, 'UTF-8')) {
                throw new InvalidArgumentException('Rendered registration decision memo is not a valid Memo-sized UTF-8 message.');
            }
        }

        foreach ($messages as $message) {
            $this->memoRepository->save(new Memo(
                $decision->requesterAccountId,
                null,
                null,
                $message,
                $decision->occurredAt,
                $senderName,
            ));
        }
    }
}
