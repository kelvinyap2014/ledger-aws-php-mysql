<?php

namespace App\Entity;

use App\Repository\LedgerEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LedgerEntryRepository::class)]
#[ORM\Table(name: 'ledger_entries')]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'transaction_reference', type: 'string', length: 50)]
    private string $transactionReference;

    #[ORM\Column(name: 'account_id', type: 'string', length: 36)]
    private string $accountId;

    #[ORM\Column(name: 'entry_type', type: 'string', length: 6)]
    private string $entryType; // 'DEBIT' or 'CREDIT'

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function createDebit(string $txRef, string $accountId, string $amount, string $currency, ?string $desc): self
    {
        $entry = new self();
        $entry->transactionReference = $txRef;
        $entry->accountId = $accountId;
        $entry->entryType = 'DEBIT';
        $entry->amount = $amount;
        $entry->currency = $currency;
        $entry->description = $desc;
        return $entry;
    }

    public static function createCredit(string $txRef, string $accountId, string $amount, string $currency, ?string $desc): self
    {
        $entry = new self();
        $entry->transactionReference = $txRef;
        $entry->accountId = $accountId;
        $entry->entryType = 'CREDIT';
        $entry->amount = $amount;
        $entry->currency = $currency;
        $entry->description = $desc;
        return $entry;
    }

    // Getters
    public function getId(): string { return $this->id; }
    public function getTransactionReference(): string { return $this->transactionReference; }
    public function getAccountId(): string { return $this->accountId; }
    public function getEntryType(): string { return $this->entryType; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getDescription(): ?string { return $this->description; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
