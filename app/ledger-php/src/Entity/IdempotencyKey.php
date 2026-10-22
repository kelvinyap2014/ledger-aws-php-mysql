<?php

namespace App\Entity;

use App\Repository\IdempotencyKeyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IdempotencyKeyRepository::class)]
#[ORM\Table(name: 'idempotency_keys')]
class IdempotencyKey
{
    #[ORM\Id]
    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 100)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'request_hash', type: 'string', length: 64)]
    private string $requestHash;

    #[ORM\Column(name: 'response_body', type: 'text', nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function setIdempotencyKey(string $idempotencyKey): self { $this->idempotencyKey = $idempotencyKey; return $this; }

    public function getRequestHash(): string { return $this->requestHash; }
    public function setRequestHash(string $requestHash): self { $this->requestHash = $requestHash; return $this; }

    public function getResponseBody(): ?string { return $this->responseBody; }
    public function setResponseBody(?string $responseBody): self { $this->responseBody = $responseBody; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
