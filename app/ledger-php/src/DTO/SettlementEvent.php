<?php

namespace App\DTO;

class SettlementEvent implements \JsonSerializable
{
    public function __construct(
        public readonly string $transactionReference,
        public readonly string $sourceAccountId,
        public readonly string $destinationAccountId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $timestamp
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'transactionReference' => $this->transactionReference,
            'sourceAccountId' => $this->sourceAccountId,
            'destinationAccountId' => $this->destinationAccountId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'timestamp' => $this->timestamp,
        ];
    }
}
