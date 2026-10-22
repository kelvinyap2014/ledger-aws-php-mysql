<?php

namespace App\Service;

use App\Entity\IdempotencyKey;
use App\Entity\LedgerEntry;
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
        ?string $idempotencyKeyStr,
        string $sourceAccountId,
        string $destinationAccountId,
        string $amount,
        string $currency,
        string $description
    ): array {
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new \InvalidArgumentException("Transfer amount must be strictly positive");
        }

        // 1. Handle Idempotency Check if key provided
        if ($idempotencyKeyStr) {
            $existingKey = $this->entityManager->find(IdempotencyKey::class, $idempotencyKeyStr);
            if ($existingKey) {
                return json_decode($existingKey->getResponseBody(), true);
            }
        }

        // 2. Execute within database transaction block
        $result = $this->entityManager->wrapInTransaction(function () use ($idempotencyKeyStr, $sourceAccountId, $destinationAccountId, $amount, $currency, $description) {

            // Pessimistic Lock on Accounts
            $sourceAccount = $this->accountRepository->findByIdForUpdate($sourceAccountId);
            if (!$sourceAccount) {
                throw new \InvalidArgumentException("Source account not found: {$sourceAccountId}");
            }

            $destAccount = $this->accountRepository->findByIdForUpdate($destinationAccountId);
            if (!$destAccount) {
                throw new \InvalidArgumentException("Destination account not found: {$destinationAccountId}");
            }

            if (bccomp($sourceAccount->getBalance(), $amount, 4) < 0) {
                throw new \RuntimeException("Insufficient funds in source account");
            }

            $txRef = 'TX-' . strtoupper(substr(Uuid::v4()->toBase32(), 0, 8));

            // Update account balances
            $sourceAccount->setBalance(bcsub($sourceAccount->getBalance(), $amount, 4))->setUpdatedAt(new \DateTimeImmutable());
            $destAccount->setBalance(bcadd($destAccount->getBalance(), $amount, 4))->setUpdatedAt(new \DateTimeImmutable());

            // Create Double-Entry Ledger Logs
            $debitEntry = LedgerEntry::createDebit($txRef, $sourceAccountId, $amount, $currency, $description);
            $creditEntry = LedgerEntry::createCredit($txRef, $destinationAccountId, $amount, $currency, $description);

            $this->entityManager->persist($debitEntry);
            $this->entityManager->persist($creditEntry);

            $responsePayload = [
                'status' => 'SUCCESS',
                'transactionReference' => $txRef,
                'idempotencyKey' => $idempotencyKeyStr
            ];

            // Save Idempotency record if key provided
            if ($idempotencyKeyStr) {
                $idempotencyKeyEntity = new IdempotencyKey();
                $idempotencyKeyEntity->setIdempotencyKey($idempotencyKeyStr);
                $idempotencyKeyEntity->setRequestHash(hash('sha256', $sourceAccountId . $destinationAccountId . $amount));
                $idempotencyKeyEntity->setResponseBody(json_encode($responsePayload));
                $this->entityManager->persist($idempotencyKeyEntity);
            }

            $this->entityManager->flush();

            return $responsePayload;
        });

        return $result;
    }
}
