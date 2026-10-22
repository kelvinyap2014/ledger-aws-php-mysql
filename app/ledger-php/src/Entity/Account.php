<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private ?string $id = null;

    #[ORM\Column(name: 'account_number', type: 'string', length: 34, unique: true)]
    private string $accountNumber;

    #[ORM\Column(name: 'holder_name', type: 'string', length: 100)]
    private string $holderName;

    #[ORM\Column(type: 'string', length: 3, options: ['default' => 'NZD'])]
    private string $currency = 'NZD';

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $balance = '0.0000';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'ACTIVE'])]
    private string $status = 'ACTIVE';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- Getters and Setters ---

    public function getId(): ?string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }

    public function getAccountNumber(): string { return $this->accountNumber; }
    public function setAccountNumber(string $accountNumber): self { $this->accountNumber = $accountNumber; return $this; }

    public function getHolderName(): string { return $this->holderName; }
    public function setHolderName(string $holderName): self { $this->holderName = $holderName; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = $currency; return $this; }

    public function getBalance(): string { return $this->balance; }
    public function setBalance(string $balance): self { $this->balance = $balance; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
