<?php

namespace App\Service;

use App\DTO\SettlementEvent;
use Aws\Sqs\SqsClient;
use Psr\Log\LoggerInterface;

class SqsSettlementPublisher
{
    public function __construct(
        private SqsClient $sqsClient,
        private LoggerInterface $logger,
        private string $settlementQueueUrl
    ) {}

    public function publish(SettlementEvent $event): void
    {
        $this->logger->info('Publishing settlement event to SQS Queue: {queueUrl}', [
            'queueUrl' => $this->settlementQueueUrl
        ]);

        try {
            $messageBody = json_encode($event, JSON_THROW_ON_ERROR);

            $this->sqsClient->sendMessage([
                'QueueUrl' => $this->settlementQueueUrl,
                'MessageBody' => $messageBody,
            ]);

            $this->logger->info('Published settlement event to SQS for transaction: {txRef}', [
                'txRef' => $event->transactionReference
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish settlement event to SQS', [
                'exception' => $e->getMessage()
            ]);
            // Non-blocking catch to prevent SQS downtime from failing the core financial transaction
        }
    }
}
