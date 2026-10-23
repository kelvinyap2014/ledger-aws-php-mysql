<?php

namespace App\Service;

use App\Repository\LedgerEntryRepository;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;

class StatementService
{
    public function __construct(
        private S3Client $s3Client,
        private LedgerEntryRepository $ledgerEntryRepository,
        private LoggerInterface $logger,
        private string $auditBucketName
    ) {}

    public function generateOrGetStatementPresignedUrl(string $accountId): string
    {
        $dateStr = (new \DateTimeImmutable())->format('Y-m-d');
        $objectKey = sprintf('statements/%s/statement-%s.csv', $accountId, $dateStr);

        // 1. Fetch transaction history from MySQL for the account[cite: 7]
        $entries = $this->ledgerEntryRepository->findBy(['accountId' => $accountId]);

        // 2. Generate statement content dynamically (CSV format)[cite: 7]
        $csvContent = $this->buildCsvStatement($accountId, $entries);

        // 3. Upload generated statement directly to S3[cite: 7]
        $this->logger->info('Uploading generated statement for account {accountId} to S3 bucket {bucket}', [
            'accountId' => $accountId,
            'bucket' => $this->auditBucketName
        ]);

        $this->s3Client->putObject([
            'Bucket' => $this->auditBucketName,
            'Key' => $objectKey,
            'ContentType' => 'text/csv',
            'Body' => $csvContent,
        ]);

        // 4. Generate 15-minute Presigned Download URL[cite: 7]
        $cmd = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->auditBucketName,
            'Key' => $objectKey,
        ]);

        $request = $this->s3Client->createPresignedRequest($cmd, '+15 minutes');

        return (string) $request->getUri();
    }

    private function buildCsvStatement(string $accountId, array $entries): string
    {
        $sb = "Account ID,Entry ID,Type,Amount,Currency,Timestamp\n";

        if (empty($entries)) {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $sb .= sprintf("%s,N/A,NO_TRANSACTIONS,0.00,NZD,%s\n", $accountId, $now);
        } else {
            foreach ($entries as $entry) {
                $sb .= sprintf(
                    "%s,%s,%s,%s,%s,%s\n",
                    $entry->getAccountId(),
                    $entry->getId(),
                    $entry->getEntryType(),
                    $entry->getAmount(),
                    $entry->getCurrency(),
                    $entry->getCreatedAt()?->format(\DateTimeInterface::ATOM) ?? ''
                );
            }
        }

        return $sb;
    }
}
