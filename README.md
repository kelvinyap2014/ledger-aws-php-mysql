# LedgerStream (Ledger/Accounts, FinTech) Pet Project
This pet project is to demonstrate the skills of AI-assisted technical design and implementation using:
- PHP, Symfony, Doctrine ORM
- MySQL
- Node.js / NestJS
- AWS - ECS/Fargate, ALB, RDS, S3, Lambda, SQS, serverless architecture
- Terraform
- Git/GitHub

# Technical Design Document: LedgerStream (Ledger/Accounts, FinTech)

## 1. System Overview & Architecture

LedgerStream is a core financial ledger and transaction processing service designed for high reliability, strict ACID compliance, and secure cloud-native deployment on **AWS ECS with Fargate**, backed by **Amazon RDS MySQL**, **AWS S3**, and **AWS SQS/Lambda**.

```mermaid
graph TD
    Client["Client / API Consumer"] -->|HTTP POST /transfers| ALB["AWS Application Load Balancer"]
    ALB -->|Forward Request| Fargate["AWS ECS Task on Fargate (PHP Symfony)"]

    subgraph "State & Storage"
        Fargate -->|ACID Transaction / Pessimistic Lock| RDS["AWS RDS MySQL: Accounts & Ledger"]
        Fargate -->|Upload / Presigned URLs| S3["AWS S3 Bucket: Statements & Audits"]
    end

    Fargate -->|Send Event| SQS["AWS SQS Queue"]
    SQS -->|Trigger| Lambda["AWS Lambda: NestJS Settlement Processor"]
    Lambda -->|Update Status| RDS
```

## 2. Core Transaction Flow
The sequence diagram below illustrates how client requests are processed through double-entry database constraints with pessimistic locking, and emitted asynchronously for settlement.

```mermaid
sequenceDiagram
    autonumber
    actor Client
    participant App as PHP Symfony API (Fargate)
    participant DB as MySQL (RDS)
    participant S3 as AWS S3
    participant SQS as AWS SQS
    participant Lambda as AWS Lambda (NestJS)

    Note over Client, DB: Step 1: Execute Double-Entry Transaction
    Client->>App: POST /api/v1/transfers (Idempotency-Key, Payload)
    App->>DB: Check Idempotency Key & Account Statuses
    App->>DB: BEGIN TRANSACTION (Pessimistic Lock on Accounts)
    App->>DB: Create Double-Entry Ledger Records (Debit & Credit)
    App->>DB: Update Account Balances
    App->>DB: COMMIT TRANSACTION
    App-->>Client: 201 Created (Transfer Details)

    Note over App, Lambda: Step 2: Asynchronous Settlement & Reconciliation
    App->>SQS: Send TransactionSettledEvent
    SQS->>Lambda: Trigger Settlement Processor
    Lambda->>DB: Update Settlement / Reconciliation Log Status

    Note over Client, S3: Step 3: Secure Statement / Audit Artifact Retrieval
    Client->>App: GET /api/v1/accounts/{id}/statement/url
    App->>S3: Generate Pre-signed URL (Expiry: 15 mins)
    App-->>Client: 200 OK (Pre-signed S3 URL)
```

## 3. Database Schema & Mock Data (Doctrine ORM / SQL Migrations)

### 3.1 Initial Schema (001-initial-schema.sql)

```sql
CREATE TABLE accounts (
    id VARCHAR(36) PRIMARY KEY,
    account_number VARCHAR(34) NOT NULL UNIQUE,
    holder_name VARCHAR(100) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'NZD',
    balance DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE ledger_entries (
    id VARCHAR(36) PRIMARY KEY,
    transaction_reference VARCHAR(50) NOT NULL,
    account_id VARCHAR(36) NOT NULL,
    entry_type VARCHAR(6) NOT NULL CHECK (entry_type IN ('DEBIT', 'CREDIT')),
    amount DECIMAL(19, 4) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'NZD',
    description VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ledger_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE idempotency_keys (
    idempotency_key VARCHAR(100) PRIMARY KEY,
    request_hash VARCHAR(64) NOT NULL,
    response_body TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_ledger_tx_ref ON ledger_entries(transaction_reference);
CREATE INDEX idx_ledger_account_id ON ledger_entries(account_id);
```

### 3.2 Sample Mock Data (001-test-sample-mock-data.sql)

```sql
INSERT INTO accounts (id, account_number, holder_name, currency, balance, status) VALUES
('acc-001-nzd', 'NZ01-8839-0012938-00', 'Kelvin Smith', 'NZD', 15000.0000, 'ACTIVE'),
('acc-002-nzd', 'NZ01-8839-0098412-00', 'Treasury Ops', 'NZD', 500000.0000, 'ACTIVE'),
('acc-003-nzd', 'NZ01-8839-0044123-00', 'Jane Doe', 'NZD', 250.5000, 'ACTIVE');

INSERT INTO ledger_entries (id, transaction_reference, account_id, entry_type, amount, currency, description) VALUES
('tx-init-001', 'TX-INIT-20261018-01', 'acc-002-nzd', 'DEBIT', 15000.0000, 'NZD', 'Initial funding for Kelvin Smith'),
('tx-init-002', 'TX-INIT-20261018-01', 'acc-001-nzd', 'CREDIT', 15000.0000, 'NZD', 'Initial account balance creation');
```

## 4. Application Implementation Details

### 4.1 Core Transfer Service (TransferService.php)

```php
<?php

namespace App\Service;

use App\Entity\IdempotencyKey;
use App\Entity\LedgerEntry;
use App\Repository\AccountRepository;
use App\Repository\IdempotencyRepository;
use App\Repository\LedgerRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

class TransferService
{
    public function __construct(
        private AccountRepository $accountRepository,
        private LedgerRepository $ledgerRepository,
        private IdempotencyRepository $idempotencyRepository,
        private EntityManagerInterface $entityManager
    ) {}

    public function executeTransfer(
        ?string $idempotencyKey,
        string $sourceAccountId,
        string $destinationAccountId,
        string $amount,
        string $currency,
        ?string $description
    ): string {
        // 1. Enforce Idempotency if key is provided
        if (!empty($idempotencyKey)) {
            $existingKey = $this->idempotencyRepository->find($idempotencyKey);
            if ($existingKey) {
                // Request has already been processed! Return the stored transaction reference.
                return $existingKey->getResponseBody();
            }
        }

        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Transfer amount must be strictly positive');
        }

        return $this->entityManager->wrapInTransaction(function () use ($idempotencyKey, $sourceAccountId, $destinationAccountId, $amount, $currency, $description) {
            // 2. Lock accounts using pessimistic write lock
            $sourceAccount = $this->accountRepository->findForUpdate($sourceAccountId);
            if (!$sourceAccount) {
                throw new InvalidArgumentException("Source account not found: " . $sourceAccountId);
            }

            $destAccount = $this->accountRepository->findForUpdate($destinationAccountId);
            if (!$destAccount) {
                throw new InvalidArgumentException("Destination account not found: " . $destinationAccountId);
            }

            // 3. Validate sufficient funds
            if (bccomp($sourceAccount->getBalance(), $amount, 4) < 0) {
                throw new RuntimeException("Insufficient funds in source account");
            }

            // 4. Generate unique transaction reference
            $txRef = 'TX-' . strtoupper(substr(Uuid::v4()->toBase32(), 0, 8));

            // 5. Create balanced double-entry ledger legs
            $debitEntry = LedgerEntry::createDebit($txRef, $sourceAccount, $amount, $currency, $description);
            $creditEntry = LedgerEntry::createCredit($txRef, $destAccount, $amount, $currency, $description);

            $this->entityManager->persist($debitEntry);
            $this->entityManager->persist($creditEntry);

            // 6. Update balances atomically
            $sourceAccount->setBalance(bcsub($sourceAccount->getBalance(), $amount, 4));
            $destAccount->setBalance(bcadd($destAccount->getBalance(), $amount, 4));

            // 7. Save Idempotency Key to prevent duplicate processing on retry
            if (!empty($idempotencyKey)) {
                $keyRecord = new IdempotencyKey();
                $keyRecord->setIdempotencyKey($idempotencyKey);
                $keyRecord->setRequestHash(hash('sha256', $sourceAccountId . $destinationAccountId . $amount));
                $keyRecord->setResponseBody($txRef);
                $this->entityManager->persist($keyRecord);
            }

            return $txRef;
        });
    }
}
```

### 4.2 PHPUnit Integration Test Suite (TransferServiceIntegrationTest.php)

```php
<?php

namespace App\Tests\Service;

use App\Repository\AccountRepository;
use App\Service\TransferService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransferServiceIntegrationTest extends KernelTestCase
{
    private ?TransferService $transferService;
    private ?AccountRepository $accountRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->transferService = $container->get(TransferService::class);
        $this->accountRepository = $container->get(AccountRepository::class);
    }

    public function testExecuteTransferIntegrationSuccess(): void
    {
        $sourceId = 'acc-001-nzd'; // Kelvin Smith: 15000.0000
        $destId = 'acc-003-nzd';   // Jane Doe: 250.5000
        $transferAmount = '500.0000';

        $txRef = $this->transferService->executeTransfer(
            'test-idempotency-key-01',
            $sourceId,
            $destId,
            $transferAmount,
            'NZD',
            'Integration test payment'
        );

        $this->assertNotNull($txRef);
        $this->assertStringStartsWith('TX-', $txRef);

        $updatedSource = $this->accountRepository->find($sourceId);
        $updatedDest = $this->accountRepository->find($destId);

        $this->assertEquals('14500.0000', $updatedSource->getBalance());
        $this->assertEquals('750.5000', $updatedDest->getBalance());
    }
}
```

## 5. Deployment & Operational Runbook

- Build Container: Package the PHP Symfony application into a multi-stage Docker image and push to AWS ECR.

- Database Migration: Doctrine Migrations automatically run migrations on deployment against RDS MySQL.

- AWS Fargate Deployment: Deploy ECS Task Definition behind an Application Load Balancer (ALB) with secure private VPC subnets.

- Testing: Issue HTTP POST requests to the ALB endpoint with appropriate Idempotency-Key headers.

# Development

## File structure

```text
ledger-aws-php-mysql/
├── .github/workflows/          # CI/CD pipeline (GitHub Actions for builds, tests)
├── app/
│   ├── ledger-php/             # Symfony PHP Backend Component
│   │   ├── bin/                # Console scripts (e.g., console)
│   │   ├── config/             # Symfony configuration (packages, services, routes)
│   │   ├── migrations/         # Doctrine database migrations
│   │   ├── src/                # PHP Source Code
│   │   │   ├── Controller/     # REST Controllers (Transfers, Accounts)
│   │   │   ├── Entity/         # Doctrine Domain Entities & Mapping
│   │   │   ├── Repository/     # Doctrine Repositories
│   │   │   └── Service/        # Business Logic (TransferService, S3 Service)
│   │   ├── tests/              # PHPUnit Unit & Integration Tests
│   │   ├── composer.json       # PHP package dependencies & autoloading (PSR-4)
│   │   ├── .env                # Local environment variables (Database DSN, AWS config)
│   │   └── Dockerfile          # Multi-stage Docker build for Symfony on Fargate
│   │
│   └── lambda/                 # AWS Lambda Functions
│       └── settlement-handler/ # Asynchronous Settlement Processor (NestJS / Node.js)
│           ├── src/            # NestJS Lambda entrypoint & handlers
│           ├── package.json    # Node dependencies
│           └── tsconfig.json   # TypeScript configuration
│
├── terraform/                  # Infrastructure as Code (AWS ECS Fargate, RDS, ALB, S3, SQS, Lambda)
│   ├── variables.tf            # Environment parameters
│   ├── provider.tf             # AWS provider setup
│   ├── vpc.tf                  # Networking & Subnets
│   ├── alb.tf                  # Application Load Balancer
│   ├── ecs.tf                  # ECS Cluster & Fargate Task Definition
│   ├── rds.tf                  # Amazon RDS MySQL instance
│   ├── s3.tf                   # S3 Bucket for audit statements
│   ├── lambda.tf               # Lambda resource setup
│   └── sqs.tf                  # SQS Queue setup
├── docker-compose.yml          # Local development stack (MySQL + PHP FPM / FrankenPHP)
├── Makefile                    # Automation for local build, Docker build, and testing
└── README.md                   # Comprehensive architecture & setup guide
```

## Infrastructure as Code

### AWS Commands
```bash
aws login
aws sts get-caller-identity
aws configure

aws lambda list-functions --region ap-southeast-2
aws lambda get-function --function-name ledger-stream-settlement-handler-dev --region ap-southeast-2
aws logs tail /aws/lambda/ledger-stream-settlement-handler-dev --follow --region ap-southeast-2
```

### Terraform Commands
```bash
cd terraform

# NEW AWS EXPERIENCE: Load credentials from cache before running terraform
source ./setup-aws-credentials.sh

# Initialize any providers or modules (good habit)
terraform init

# DEV environment — plan and apply
terraform plan -var-file="terraform-dev.tfvars"
# Apply changes interactively (you'll be prompted with a summary, type 'yes' to confirm)
terraform apply -var-file="terraform-dev.tfvars"

# PROD environment — plan and apply
terraform plan -var-file="terraform-prod.tfvars"
terraform apply -var-file="terraform-prod.tfvars"

# Destroy resources (cleanup)
terraform destroy -var-file="terraform-dev.tfvars"
```

### Build the Docker Image Locally and push to AWS ECR
```bash
# Create the ECR repository
terraform apply -var-file="terraform-dev.tfvars" -target=aws_ecr_repository.ledger_stream

# Authenticate your local Docker client to your AWS ECR registry
aws ecr get-login-password --region <region> | docker login --username AWS --password-stdin <aws-account-id>.dkr.ecr.<region>.amazonaws.com

# Build your Docker image from your project root directory
docker build -t ledger-stream-php .

# Tag your local image so Docker knows which ECR repository and tag it
docker tag ledger-stream-php:latest <aws-account-id>.dkr.ecr.<region>.amazonaws.com/ledger-stream-php:latest

# Push the tagged image up to your ECR repository
docker push <aws-account-id>.dkr.ecr.<region>.amazonaws.com/ledger-stream-php:latest

aws ecs update-service --cluster ledger-stream-cluster-dev --service ledger-stream-service-dev --force-new-deployment --region ap-southeast-2

aws logs tail /ecs/ledger-stream-dev --follow --region ap-southeast-2
```

#### Test Docker Image Locally
```bash
docker run --rm \
  -p 8080:80 \
  -e APP_ENV=prod \
  -e DATABASE_URL="mysql://ledgerstream:secret@172.17.0.1:3306/ledgerdb?serverVersion=8.0&charset=utf8mb4" \
  -e SQS_SETTLEMENT_QUEUE_URL=https://sqs.ap-southeast-2.amazonaws.com/<aws-account-id>/ledger-settlement-queue-dev \
  -e AWS_REGION=ap-southeast-2 \
  -e AWS_S3_AUDIT_BUCKET_NAME=ledger-audit-statements-dev \
  ledger-stream-php:latest
```

## PHP Development

### How to bring up MySQL with ledger-php app
```bash
# at root folder
docker compose up
```

### How to Run Symfony Local Development Server
```bash
cd app/ledger-php
composer install
php bin/console doctrine:migrations:migrate
symfony server:start
```

### Background: How PHP Symfony was setup from scratch
```bash
mkdir -p app/ledger-php
cd app/ledger-php

# Required: PHP 8.3 CLI and Composer are installed in local machine
composer create-project symfony/skeleton:"^7.0" .

# Or use Composer (via docker) to initialize the Symfony Skeleton
# docker run --rm -it -v $(pwd):/app composer:latest create-project symfony/skeleton:"^7.0" .

# Require Essential Packages
composer require webapp
composer require symfony/orm-pack
composer require --dev symfony/maker-bundle
```

## NestJS (Node.js) Development

### Quick Start: Build & Package Lambda Functions

```bash
cd lambda/settlement-handler

# 1. Install dependencies
npm install

# 2. Build NestJS Lambda handler
npm run build

# 3. Package artifact for Lambda deployment
zip -r function.zip dist/ node_modules/ package.json
```

# Testing

## Test API with curl

### 1. Start the Application
```bash
symfony server:start
```

### 2. Get Account Statement URL Request
```bash
curl -X GET "http://localhost:8000/api/v1/accounts/acc-001-nzd/statement/url" \
     -H "Accept: application/json"
```

### 3. Execute a Transfer Request
```bash
curl -X POST http://localhost:8000/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{
    "sourceAccountId": "acc-001-nzd",
    "destinationAccountId": "acc-002-nzd",
    "amount": 250.00,
    "currency": "NZD",
    "description": "Peer-to-peer payment"
  }'
```

**Expected Response (201 Created):**
```json
{
  "status": "SUCCESS",
  "transactionReference": "TX-A1B2C3D4",
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000"
}
```

### 4. Verify Idempotency — Resubmit with Same Key
```bash
curl -X POST http://localhost:8000/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{
    "sourceAccountId": "acc-001-nzd",
    "destinationAccountId": "acc-002-nzd",
    "amount": 250.00,
    "currency": "NZD",
    "description": "Peer-to-peer payment"
  }'
```
Should return the **same transaction reference** and HTTP 201 without creating duplicate entries.

### 5. Test Insufficient Funds Scenario
```bash
curl -X POST http://localhost:8000/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "sourceAccountId": "acc-003-nzd",
    "destinationAccountId": "acc-002-nzd",
    "amount": 10000.00,
    "currency": "NZD",
    "description": "Large transfer"
  }'
```

**Expected Response (400 Bad Request):**
```json
{
  "timestamp": "2026-10-22T10:48:50+13:00",
  "message": "Insufficient funds in source account",
  "error": "Bad Request",
  "status": 400
}
```
