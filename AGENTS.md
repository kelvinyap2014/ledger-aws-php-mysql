# Agent Guidance: LedgerStream (Ledger/Accounts, FinTech) Pet Project — PHP MySQL

## Project Overview

**LedgerStream** is a core financial ledger and transaction processing service designed for high reliability, strict ACID compliance, and secure cloud-native deployment on **AWS ECS with Fargate**, backed by **Amazon RDS MySQL**, **AWS S3**, and **AWS SQS/Lambda**.

This pet project demonstrates AI-assisted technical design and implementation using:
- **Backend:** PHP 8.3, Symfony 7, Doctrine ORM
- **Database:** Amazon RDS MySQL 8.0
- **Serverless:** AWS ECS/Fargate, AWS Lambda (TypeScript/NestJS)
- **Messaging:** AWS SQS
- **Storage:** AWS S3 (audit statements & presigned URLs)
- **Infrastructure:** Terraform, Application Load Balancer (ALB)
- **Version Control:** Git/GitHub

---

## Project Structure

```
ledger-aws-php-mysql/
├── .github/workflows/              # CI/CD pipeline (GitHub Actions)
├── app/
│   ├── ledger-php/                 # Symfony PHP Backend
│   │   ├── bin/                    # Console scripts
│   │   ├── config/                 # Symfony configuration
│   │   ├── migrations/             # Doctrine database migrations
│   │   ├── src/
│   │   │   ├── Controller/         # REST Controllers
│   │   │   ├── Entity/             # Doctrine Entities
│   │   │   ├── Repository/         # Doctrine Repositories
│   │   │   └── Service/            # Business Logic (TransferService, S3 Service)
│   │   ├── tests/                  # PHPUnit Unit & Integration Tests
│   │   ├── composer.json           # PHP dependencies (PSR-4 autoloading)
│   │   ├── .env                    # Environment variables
│   │   └── Dockerfile              # Multi-stage Docker build
│   │
│   └── lambda/                     # AWS Lambda Functions
│       └── settlement-handler/     # Settlement Processor (Node.js/NestJS)
│           ├── src/                # NestJS handler
│           ├── package.json        # Node dependencies
│           └── tsconfig.json       # TypeScript config
│
├── terraform/                      # Infrastructure as Code
│   ├── variables.tf                # Common variables (region, env, project_name)
│   ├── provider.tf                 # AWS provider setup
│   ├── vpc.tf                      # Networking (optional)
│   ├── alb.tf                      # Application Load Balancer
│   ├── ecs.tf                      # ECS Cluster & Fargate Task Definition
│   ├── rds.tf                      # Amazon RDS MySQL
│   ├── s3.tf                       # S3 Bucket
│   ├── lambda.tf                   # Lambda function definitions
│   ├── sqs.tf                      # SQS Queue
│   ├── iam.tf                      # IAM roles & policies
│   ├── setup-aws-credentials.sh    # AWS credential loader for new AWS experience
│   ├── terraform-dev.tfvars        # Development environment overrides
│   ├── terraform-prod.tfvars       # Production environment overrides
│   └── terraform.tfstate           # Terraform state (git-ignored in practice)
│
├── docker-compose.yml              # Local development stack (MySQL + PHP FPM)
├── README.md                       # Architecture & setup guide
└── AGENTS.md                       # This file
```

---

## Architecture Overview

```
Client → ALB → ECS Task (PHP Symfony/Fargate) → RDS MySQL
                                             ↓
                                        AWS S3 (statements)
                                             ↓
                                        AWS SQS (events)
                                             ↓
                                     AWS Lambda (NestJS settlement)
```

### Core Features
1. **Double-Entry Ledger** — All transactions recorded as debit/credit pairs (ACID)
2. **Pessimistic Locking** — Account-level locks prevent race conditions
3. **Idempotency** — Request deduplication via idempotency keys
4. **Asynchronous Settlement** — Events published to SQS, processed by Lambda
5. **Statement Export** — Pre-signed S3 URLs for secure document retrieval

---

## Build & Development Commands

### PHP / Symfony

**Quick Reference:**
```bash
cd app/ledger-php

# Install dependencies
composer install

# Run migrations
php bin/console doctrine:migrations:migrate

# Run local development server
symfony server:start

# Run tests
php bin/phpunit

# Compile container image (from project root)
cd ../..
docker build -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php
```

**Requirements:**
- PHP 8.3 CLI installed locally
- Composer installed
- Symfony CLI (for `symfony server:start`)
- Doctrine ORM + Migrations Bundle

### TypeScript / Lambda Settlement Handler

**Quick Reference:**
```bash
cd app/lambda/settlement-handler

# Install dependencies
npm install

# Build NestJS project
npm run build

# Package as function.zip for AWS Lambda
./build.sh

# Or use npm script
npm run package
```

**Requirements:**
- Node.js 18+ installed
- NestJS CLI (npm package)
- TypeScript compiler
- esbuild bundler

### Docker Compose (Local Development)

```bash
# At project root
docker-compose up --build

# Run migrations inside container
docker exec -it ledger_php php bin/console doctrine:migrations:migrate --no-interaction

# Restart MySQL cleanly (fresh volume)
docker-compose down -v
docker-compose up --build
```

---

## AWS Infrastructure (Terraform)

All Terraform code is in the `terraform/` directory. Key commands:

### Terraform Workflow (New AWS Experience)

> **CRITICAL:** The new AWS experience uses a credential cache. Before running Terraform,
> load credentials from the cache using the provided setup script.

```bash
cd terraform

# 1. Load AWS credentials from new AWS experience cache
source ./setup-aws-credentials.sh

# 2. Initialize Terraform (first time or after provider upgrades)
terraform init

# 3. Plan changes (always review before apply)
terraform plan -var-file="terraform-dev.tfvars"

# 4. Apply changes (DEV environment)
terraform apply -var-file="terraform-dev.tfvars"

# 5. For PROD (replace terraform-dev.tfvars with terraform-prod.tfvars)
terraform plan -var-file="terraform-prod.tfvars"
terraform apply -var-file="terraform-prod.tfvars"

# 6. Destroy all resources (cleanup / cost control)
terraform destroy -var-file="terraform-dev.tfvars"
```

### AWS Region Constraint

All Regional resources **MUST** be created in **ap-southeast-2** (your assigned region via AWS Settings).
- Lambda, API Gateway, ECS, ALB, RDS, SQS — all in ap-southeast-2
- Do NOT attempt to create these in other Regions
- Confirm your assigned Region in AWS Settings > View all projects > Overview > Additional Info > Region

### Common Terraform Commands

```bash
# Create only the ECR repository (for manual Docker push)
terraform apply -var-file="terraform-dev.tfvars" -target=aws_ecr_repository.ledger_stream

# Create only the RDS database
terraform apply -var-file="terraform-dev.tfvars" -target=aws_db_instance.ledger_mysql

# Refresh state (check for manual changes in AWS console)
terraform refresh -var-file="terraform-dev.tfvars"

# Show current state
terraform show

# Validate configuration (before committing)
terraform validate
```

---

## Docker & ECR Workflow

### Build & Push to AWS ECR

```bash
# 1. Create ECR repository via Terraform
cd terraform
source ./setup-aws-credentials.sh
terraform apply -var-file="terraform-dev.tfvars" -target=aws_ecr_repository.ledger_stream

# 2. Authenticate Docker client to ECR
aws ecr get-login-password --region ap-southeast-2 | \
  docker login --username AWS --password-stdin 11111.dkr.ecr.ap-southeast-2.amazonaws.com

# 3. Build Docker image (from project root)
cd ..
docker build -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php

# 4. Tag for ECR (replace account ID if different)
docker tag ledger-stream-php:latest \
  11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest

# 5. Push to ECR
docker push 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest

# 6. Force new ECS deployment (uses latest image)
aws ecs update-service \
  --cluster ledger-stream-cluster-dev \
  --service ledger-stream-service-dev \
  --force-new-deployment \
  --region ap-southeast-2

# 7. Monitor ECS logs
aws logs tail /ecs/ledger-stream-dev --follow --region ap-southeast-2
```

### Test Docker Image Locally

```bash
docker run --rm \
  -p 8080:8080 \
  -e DATABASE_URL="mysql://root:rootpassword@172.17.0.1:3306/ledger_db?serverVersion=8.0&charset=utf8mb4" \
  -e SQS_SETTLEMENT_QUEUE_URL=https://sqs.ap-southeast-2.amazonaws.com/<aws-account-id>/ledger-settlement-queue-dev \
  -e AWS_REGION=ap-southeast-2 \
  -e AWS_S3_AUDIT_BUCKET_NAME=ledger-audit-statements-dev \
  ledger-stream-php:latest
```

---

## AWS CLI Commands

```bash
# View Lambda function status
aws lambda list-functions --region ap-southeast-2
aws lambda get-function --function-name ledger-stream-settlement-handler-dev --region ap-southeast-2

# Monitor Lambda logs
aws logs tail /aws/lambda/ledger-stream-settlement-handler-dev --follow --region ap-southeast-2

# Check RDS instance status
aws rds describe-db-instances --region ap-southeast-2

# Get ALB DNS endpoint (for API testing)
aws elbv2 describe-load-balancers --region ap-southeast-2 | jq '.LoadBalancers[0].DNSName'

# List SQS queues
aws sqs list-queues --region ap-southeast-2

# Monitor SQS queue depth
aws sqs get-queue-attributes \
  --queue-url https://sqs.ap-southeast-2.amazonaws.com/<account-id>/ledger-settlement-queue-dev \
  --attribute-names ApproximateNumberOfMessages
```

---

## Development Workflow (Typical Sprint)

1. **Feature Branch** — Create feature branch in Git
   ```bash
   git checkout -b feature/xyz
   ```

2. **Local Development**:
   ```bash
   cd app/ledger-php
   composer install
   php bin/console doctrine:migrations:migrate
   symfony server:start
   ```

3. **Write Tests** — Add unit tests in `tests/` folder
   ```bash
   php bin/phpunit
   ```

4. **Docker Validation** — Test containerization
   ```bash
   docker-compose up --build
   docker exec -it ledger_php php bin/phpunit
   ```

5. **Infrastructure Changes** (if any):
   ```bash
   cd terraform
   terraform plan -var-file="terraform-dev.tfvars"
   ```

6. **Build & Push to ECR** (when ready for deployment):
   ```bash
   docker build -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php
   docker tag ledger-stream-php:latest \
     11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest
   docker push 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest
   ```

7. **Commit & Push** — Push feature branch to GitHub
   ```bash
   git add .
   git commit -m "feat: add xyz"
   git push origin feature/xyz
   ```

8. **Code Review** — Create pull request, wait for approval

9. **Deploy** — Merge to main, run Terraform apply on production
   ```bash
   cd terraform
   terraform apply -var-file="terraform-prod.tfvars"
   ```

---

## Testing

### Manual API Testing (curl)

**Start the application:**
```bash
docker-compose up --build
```

**Health check:**
```bash
curl -X GET "http://localhost:8000/health"
```

**Get account statement URL:**
```bash
curl -X GET "http://localhost:8000/api/v1/accounts/acc-001-nzd/statement/url" \
     -H "Accept: application/json"
```

**Execute a transfer:**
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

**Expected response (201 Created):**
```json
{
  "status": "SUCCESS",
  "transactionReference": "TX-A1B2C3D4",
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Verify idempotency — resubmit with same key (should return same tx reference):**
```bash
curl -X POST http://localhost:8000/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{...}'
```

### Unit & Integration Tests

```bash
cd app/ledger-php

# Run all tests
php bin/phpunit

# Run specific test class
php bin/phpunit tests/Controller/TransferControllerTest.php

# Run with coverage report
php bin/phpunit --coverage-html=coverage/
```

---

## Available Skills

Load a skill with the Read tool for detailed step-by-step guidance:

| Skill | When to Use |
|---|---|
| `php-builds` (.agents/skills/php-builds/SKILL.md) | PHP Symfony build & test workflow, Docker setup, testing procedures |

---

## Key Environment Files

| File | Purpose |
|---|---|
| `app/ledger-php/composer.json` | PHP dependencies, PSR-4 autoloading |
| `app/ledger-php/.env` | Environment variables (local override of .env.local) |
| `app/ledger-php/config/packages/` | Symfony bundle configuration |
| `app/lambda/settlement-handler/package.json` | Node.js dependencies |
| `terraform/variables.tf` | Common Terraform variables |
| `terraform/terraform-dev.tfvars` | Development environment overrides |
| `docker-compose.yml` | Local MySQL + PHP FPM stack definition |
| `.github/workflows/` | CI/CD pipeline (build, test, deploy) |

---

## Stop & Consult User If

- Build or compilation fails with unclear error messages (attempt 5+ debug cycles, then consult)
- Terraform plan shows unexpected resource changes or destruction
- AWS credentials expire or are missing (re-run `source ./setup-aws-credentials.sh`)
- Need to modify assigned Region or deploy to production with manual approval
- Database schema changes require migration rollback or significant refactoring
- Performance regression > 30% below baseline latency targets
- Unable to achieve 0 errors/warnings in PHP linter or compilation

---

## New AWS Experience Constraints

- **Region Constraint:** All resources MUST be deployed to **ap-southeast-2**
- **Credential Cache:** Always run `source ./setup-aws-credentials.sh` before Terraform commands
- **Service Availability:** Confirm service availability in ap-southeast-2 before attempting deployment
- **Spend Limit:** If you have a spend limit and resources become inaccessible, check AWS Settings > Billing
- **Supported Services:** Check AWS free tier / paid tier service lists if a service is unavailable
- **Cost Management:** Always review `terraform plan` before applying; use `terraform destroy` to clean up unused resources

---

## Helpful Commands Quick Reference

```bash
# PHP / Symfony
cd app/ledger-php && composer install
php bin/console doctrine:migrations:migrate
symfony server:start
php bin/phpunit

# Docker / Docker Compose
docker-compose up --build
docker-compose down -v

# Terraform (with credential setup)
cd terraform && source ./setup-aws-credentials.sh
terraform init
terraform plan -var-file="terraform-dev.tfvars"
terraform apply -var-file="terraform-dev.tfvars"
terraform destroy -var-file="terraform-dev.tfvars"

# ECR & Docker
docker build -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php
docker tag ledger-stream-php:latest 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest
docker push 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest

# AWS CLI
aws ecr get-login-password --region ap-southeast-2 | docker login --username AWS --password-stdin 11111.dkr.ecr.ap-southeast-2.amazonaws.com
aws logs tail /ecs/ledger-stream-dev --follow --region ap-southeast-2
aws logs tail /aws/lambda/ledger-stream-settlement-handler-dev --follow --region ap-southeast-2

# Git
git checkout -b feature/xyz
git commit -m "message"
git push origin feature/xyz
```

---

## References

- **README.md** — Full architecture & system design documentation
- **app/ledger-php/Dockerfile** — Multi-stage Docker build for Symfony
- **terraform/*** — Infrastructure definition files
- **docker-compose.yml** — Local development environment
