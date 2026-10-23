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
INSERT INTO accounts (id, account_number, holder_name, currency, balance, status, created_at, updated_at) VALUES
('acc-001-nzd', 'NZ01-8839-0012938-00', 'Kelvin Smith', 'NZD', 15000.0000, 'ACTIVE', NOW(), NOW()),
('acc-002-nzd', 'NZ01-8839-0098412-00', 'Treasury Ops', 'NZD', 500000.0000, 'ACTIVE', NOW(), NOW()),
('acc-003-nzd', 'NZ01-8839-0044123-00', 'Jane Doe', 'NZD', 250.5000, 'ACTIVE', NOW(), NOW());

INSERT INTO ledger_entries (id, transaction_reference, account_id, entry_type, amount, currency, description, created_at) VALUES
('tx-init-001', 'TX-INIT-20261018-01', 'acc-002-nzd', 'DEBIT', 15000.0000, 'NZD', 'Initial funding for Kelvin Smith', NOW()),
('tx-init-002', 'TX-INIT-20261018-01', 'acc-001-nzd', 'CREDIT', 15000.0000, 'NZD', 'Initial account balance creation', NOW());
```

## 4. Application Implementation Details

### 4.1 Core Transfer Service (TransferService.php)

```php
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
```

### 4.2 PHPUnit Unit Test (TransferControllerTest.php)

```php
<?php

<?php

namespace App\Tests\Controller;

use App\Service\TransferService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TransferControllerTest extends WebTestCase
{
    public function testTransferControllerCallsServiceCorrectly(): void
    {
        $client = static::createClient();

        // Setup mock service with array return type
        $expectedTxRef = 'TX-12345678';
        $serviceResponse = [
            'status' => 'SUCCESS',
            'transactionReference' => $expectedTxRef,
            'idempotencyKey' => 'test-key-abc'
        ];

        $transferService = $this->createMock(TransferService::class);
        $transferService->expects($this->once())
            ->method('executeTransfer')
            ->with(
                'test-key-abc',
                'acc-001',
                'acc-002',
                '250.00',
                'NZD',
                'Test Transfer'
            )
            ->willReturn($serviceResponse);

        // Override the service in the container
        static::getContainer()->set(TransferService::class, $transferService);

        // Make POST request
        $client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'HTTP_IDEMPOTENCY_KEY' => 'test-key-abc',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'sourceAccountId' => 'acc-001',
                'destinationAccountId' => 'acc-002',
                'amount' => '250.00',
                'currency' => 'NZD',
                'description' => 'Test Transfer',
            ])
        );

        $response = $client->getResponse();

        // Then
        $this->assertEquals(201, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertNotNull($responseData);
        $this->assertEquals('SUCCESS', $responseData['status']);
        $this->assertEquals($expectedTxRef, $responseData['transactionReference']);
        $this->assertEquals('test-key-abc', $responseData['idempotencyKey']);
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
# terraform apply -var-file="terraform-dev.tfvars" -auto-approve

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

# Build your Docker image from your PHP project directory
cd app/ledger-php
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
  -p 8080:8080 \
  -e DATABASE_URL="mysql://root:rootpassword@172.17.0.1:3306/ledger_db?serverVersion=8.0&charset=utf8mb4" \
  -e SQS_SETTLEMENT_QUEUE_URL=https://sqs.ap-southeast-2.amazonaws.com/<aws-account-id>/ledger-settlement-queue-dev \
  -e AWS_REGION=ap-southeast-2 \
  -e AWS_S3_AUDIT_BUCKET_NAME=ledger-audit-statements-dev \
  ledger-stream-php:latest
```

## PHP Development

### Quick Start: How to bring up MySQL with ledger-php app
```bash
cd app/ledger-php
docker run --rm -it -v $(pwd):/app composer:latest install

# at root folder
cd ../..
docker compose up --build
# Run the migration against MySQL
docker exec -it ledger_php php bin/console doctrine:migrations:migrate --no-interaction

# This ensures MySQL re-initializes fresh every time
docker compose down -v
docker compose up --build

# Faster, but reuses the old MySQL volume
docker compose restart
```

### How to Run Symfony Local Development Server
```bash
# at root folder, start MySQL server only
docker compose up mysql

cd app/ledger-php
composer install
php bin/console doctrine:migrations:migrate
symfony server:start

# Run unit test
php bin/phpunit
```

### Background: How PHP Symfony project was setup from scratch
```bash
mkdir -p app/ledger-php
cd app/ledger-php

# Required: PHP 8.3 CLI and Composer and Symfony are installed in local machine
composer create-project symfony/skeleton:"^7.0" .

# Or use Composer (via docker) to initialize the Symfony Skeleton
# docker run --rm -it -v $(pwd):/app composer:latest create-project symfony/skeleton:"^7.0" .

# Require Essential Packages
composer require webapp
composer require symfony/orm-pack
composer require --dev symfony/maker-bundle
# AWS SDK
composer require aws/aws-sdk-php

# Create your Database Entity (with Repository)
php bin/console make:entity Account
php bin/console make:entity IdempotencyKey
php bin/console make:entity LedgerEntry
# Or use PHP (via docker)
# docker exec -it ledger_php php bin/console make:entity Account

# Controller (with template)
php bin/console make:controller TransferController

# Generate the migration file
docker exec -it ledger_php php bin/console make:migration

# To generate migration from local:
# sudo apt-get install libzip-dev zip unzip php8.3-mysql php8.3-bcmath
# php bin/console make:migration
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
# at root folder
docker compose up --build

curl -X GET "http://localhost:8000/health"
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
