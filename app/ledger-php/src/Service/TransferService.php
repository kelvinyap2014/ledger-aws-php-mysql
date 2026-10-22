<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class TransferService
{
    public function __construct(
        private AccountRepository $accountRepository,
        private EntityManagerInterface $entityManager
    ) {}

    public function executeTransfer(
        ?string $idempotencyKey,
        string $sourceAccountId,
        string $destinationAccountId,
        string $amount,
        string $currency,
        string $description
    ): string {
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new \InvalidArgumentException("Transfer amount must be strictly positive");
        }

        // Database Transaction Block
        return $this->entityManager->wrapInTransaction(function () use ($idempotencyKey, $sourceAccountId, $destinationAccountId, $amount, $currency, $description) {

            // 1. Pessimistic Lock on Accounts to prevent race conditions
            $sourceAccount = $this->accountRepository->findByIdForUpdate($sourceAccountId);
            if (!$sourceAccount) {
                throw new \InvalidArgumentException("Source account not found: {$sourceAccountId}");
            }

            $destAccount = $this->accountRepository->findByIdForUpdate($destinationAccountId);
            if (!$destAccount) {
                throw new \InvalidArgumentException("Destination account not found: {$destinationAccountId}");
            }

            // 2. Validate sufficient funds using BCMath comparison
            if (bccomp($sourceAccount->getBalance(), $amount, 4) < 0) {
                throw new \IllegalStateException("Insufficient funds in source account");
            }

            // 3. Generate unique transaction reference
            $txRef = 'TX-' . strtoupper(substr(Uuid::v4()->toBase32(), 0, 8));

            // 4. Update balances securely using BCMath
            $sourceNewBalance = bcsub($sourceAccount->getBalance(), $amount, 4);
            $destNewBalance = bcadd($destAccount->getBalance(), $amount, 4);

            $now = new \DateTimeImmutable();
            $sourceAccount->setBalance($sourceNewBalance)->setUpdatedAt($now);
            $destAccount->setBalance($destNewBalance)->setUpdatedAt($now);

            $this->entityManager->flush();

            return $txRef;
        });
    }
}
