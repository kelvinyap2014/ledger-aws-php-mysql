# SKILL: PHP Symfony Build & Test Workflow

This guide provides step-by-step instructions for building, testing, and deploying the LedgerStream PHP Symfony application.

---

## Part 1: Local Development Setup

### Step 1.1 — Install Local Dependencies

Before running the application locally, ensure you have PHP 8.3, Composer, and Symfony CLI installed on your machine.

**Check PHP version:**
```bash
php --version
# Output should show PHP 8.3.x
```

**Check Composer:**
```bash
composer --version
# Output should show Composer 2.x
```

**Install Symfony CLI (if not already installed):**
```bash
# macOS / Linux
curl -1sLf https://dl.symfony.com/cli/installer | bash

# Or via Homebrew (macOS)
brew install symfony-cli/tap/symfony-cli
```

### Step 1.2 — Navigate to PHP Application Directory

```bash
cd app/ledger-php
```

### Step 1.3 — Install PHP Dependencies via Composer

```bash
composer install
```

**Expected output:**
```
Installing dependencies from lock file
  - Installing symfony/console (v7.0.0)
  - Installing symfony/orm-pack (v2.4.1)
  ...
83 packages in 5.23s
```

**If composer.lock is missing:**
```bash
composer install --no-dev  # Skip dev dependencies for production
# OR
composer install           # Include dev dependencies (recommended for local dev)
```

### Step 1.4 — Configure Environment Variables

Copy `.env` to `.env.local` and configure local database connection:

```bash
# Create local override file
cp .env .env.local

# Edit .env.local to point to your local MySQL
# Example:
# DATABASE_URL="mysql://root:rootpassword@127.0.0.1:3306/ledger_db?serverVersion=8.0&charset=utf8mb4"
```

**Common `.env.local` variables:**
```bash
# Database
DATABASE_URL="mysql://root:rootpassword@127.0.0.1:3306/ledger_db?serverVersion=8.0&charset=utf8mb4"

# Symfony environment (test, dev, prod)
APP_ENV=dev
APP_DEBUG=true

# AWS Integration (optional for local dev)
SQS_SETTLEMENT_QUEUE_URL=http://localhost:9324/queue/settlement  # LocalStack
AWS_REGION=ap-southeast-2
AWS_S3_AUDIT_BUCKET_NAME=ledger-audit-statements-dev
```

---

## Part 2: Running Database Migrations

### Step 2.1 — Verify Database Connection

```bash
# Navigate to Symfony app directory
cd app/ledger-php

# Test database connection
php bin/console doctrine:database:create --if-not-exists
```

**Expected output:**
```
Created database `ledger_db` for connection named default
```

**If connection fails:** Check `.env.local` DATABASE_URL and ensure MySQL is running locally.

### Step 2.2 — Run Doctrine Migrations

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

**Expected output:**
```
 [OK] Successfully migrated to version : DoctrineMigrations\Version20261022120000

Migrations executed: 1
```

**To view migration history:**
```bash
php bin/console doctrine:migrations:list
```

**To rollback the last migration (if needed):**
```bash
php bin/console doctrine:migrations:execute --down DoctrineMigrations\Version20261022120000
```

### Step 2.3 — Load Sample Test Data (Optional)

If you have a `migrations/` folder with test fixture data:

```bash
php bin/console doctrine:fixtures:load --no-interaction
```

Or manually load test data via SQL:
```bash
mysql -u root -p ledger_db < migrations/001-test-sample-mock-data.sql
```

---

## Part 3: Running the Local Development Server

### Step 3.1 — Start Symfony Dev Server

```bash
# From app/ledger-php directory
symfony server:start

# OR use Composer script (if configured)
php -S 127.0.0.1:8000 -t public
```

**Expected output:**
```
  [OK] Web Server listening on http://127.0.0.1:8000

 [OK] Tailing Web Server log file (/home/user/.symfony5/var/log/servers.log)
```

### Step 3.2 — Verify Server is Running

```bash
curl -X GET http://localhost:8000/health
```

**Expected response (200 OK):**
```json
{"status": "ok"}
```

### Step 3.3 — Stop the Development Server

```bash
# Press Ctrl+C in the terminal where symfony server:start is running
# OR run:
symfony server:stop
```

---

## Part 4: Testing

### Step 4.1 — Run Unit Tests

```bash
cd app/ledger-php

# Run all tests
php bin/phpunit

# Expected output:
# ...............................                              31 / 31 (100%)
# OK (31 tests, 0 assertions)
```

### Step 4.2 — Run Specific Test Class

```bash
php bin/phpunit tests/Controller/TransferControllerTest.php
```

**Expected output:**
```
PHPUnit 9.5.x by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

OK (1 test, 3 assertions)
```

### Step 4.3 — Run Tests with Coverage Report

```bash
php bin/phpunit --coverage-html=coverage/
```

**Output:** HTML coverage report generated in `coverage/index.html`

Open in browser:
```bash
open coverage/index.html  # macOS
# OR
firefox coverage/index.html  # Linux
```

### Step 4.4 — Run Tests with Verbose Output

```bash
php bin/phpunit -v

# Output shows each test method executed
# PHPUnit\Tests\Controller\TransferControllerTest::testTransferControllerCallsServiceCorrectly PASSED
```

### Step 4.5 — Run Tests Matching a Pattern

```bash
# Run only tests containing "Transfer" in the name
php bin/phpunit --filter=Transfer

# Run only tests in a specific directory
php bin/phpunit tests/Controller/
```

---

## Part 5: Docker Development Workflow

### Step 5.1 — Build Docker Image Locally

> This step requires Docker to be installed locally.

```bash
# From project root (ledger-aws-php-mysql/)
docker build -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php
```

**Expected output:**
```
[+] Building 45.2s (15/15) FINISHED
 => => naming to docker.io/library/ledger-stream-php:latest
```

### Step 5.2 — Run Containerized Application with docker-compose

```bash
# From project root
docker-compose up --build

# Expected output:
# Creating ledger-mysql ... done
# Creating ledger_php ... done
# Starting ledger_php_1 ...
```

### Step 5.3 — Run Migrations Inside Container

```bash
docker exec -it ledger_php php bin/console doctrine:migrations:migrate --no-interaction
```

### Step 5.4 — Run Tests Inside Container

```bash
docker exec -it ledger_php php bin/phpunit
```

### Step 5.5 — View Container Logs

```bash
# Tail logs in real-time
docker-compose logs -f ledger_php

# View logs from a specific service
docker-compose logs -f mysql

# View last 50 lines
docker-compose logs --tail=50
```

### Step 5.6 — Stop and Clean Up Containers

```bash
# Stop services (keep volume)
docker-compose stop

# Stop and remove containers (keep MySQL volume)
docker-compose down

# Stop and remove everything including volumes (fresh start)
docker-compose down -v

# Restart services (fast, reuses volume)
docker-compose restart
```

---

## Part 6: Manual API Testing

> Start the application first (either locally with `symfony server:start` or via `docker-compose up --build`).

### Step 6.1 — Health Check

```bash
curl -X GET http://localhost:8000/health
```

**Expected response (200 OK):**
```json
{"status": "ok"}
```

### Step 6.2 — Get Account Statement URL

```bash
curl -X GET "http://localhost:8000/api/v1/accounts/acc-001-nzd/statement/url" \
     -H "Accept: application/json"
```

**Expected response (200 OK):**
```json
{
  "accountId": "acc-001-nzd",
  "presignedUrl": "https://s3.ap-southeast-2.amazonaws.com/ledger-audit-statements-dev/...",
  "expiresIn": 900
}
```

### Step 6.3 — Execute a Transfer

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

### Step 6.4 — Test Idempotency (Replay Same Request)

```bash
# Resubmit with the SAME Idempotency-Key
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

**Expected response (201 Created):** Same as first request (no duplicate ledger entries created)

### Step 6.5 — Test Insufficient Funds Error

```bash
curl -X POST http://localhost:8000/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "sourceAccountId": "acc-003-nzd",
    "destinationAccountId": "acc-002-nzd",
    "amount": 10000.00,
    "currency": "NZD",
    "description": "Large transfer (will fail)"
  }'
```

**Expected response (400 Bad Request):**
```json
{
  "timestamp": "2026-10-24T10:48:50+13:00",
  "message": "Insufficient funds in source account",
  "error": "Bad Request",
  "status": 400
}
```

---

## Part 7: Code Quality & Linting

### Step 7.1 — Run PHP Linter

```bash
cd app/ledger-php

# Check syntax of all PHP files
php -l src/Service/TransferService.php
```

**Expected output (no errors):**
```
No syntax errors detected in src/Service/TransferService.php
```

### Step 7.2 — Run PHP Code Sniffer (if installed)

```bash
# Check PSR-12 coding standards
vendor/bin/phpcs src/

# Auto-fix common issues
vendor/bin/phpcbf src/
```

### Step 7.3 — Validate Symfony Configuration

```bash
php bin/console config:dump-reference
php bin/console lint:yaml config/
php bin/console lint:container
```

---

## Part 8: Building Docker Image for ECR Deployment

### Step 8.1 — Build Production Docker Image

```bash
# From project root
docker build -f app/ledger-php/Dockerfile \
  --target prod \
  -t ledger-stream-php:latest \
  app/ledger-php
```

### Step 8.2 — Tag Image for ECR Registry

```bash
# Replace <aws-account-id> with your AWS account ID
docker tag ledger-stream-php:latest \
  11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest
```

### Step 8.3 — Authenticate Docker to ECR

```bash
# Load AWS credentials first
cd terraform && source ./setup-aws-credentials.sh && cd ..

# Login to ECR
aws ecr get-login-password --region ap-southeast-2 | \
  docker login --username AWS --password-stdin \
  11111.dkr.ecr.ap-southeast-2.amazonaws.com
```

**Expected output:**
```
Login Succeeded
```

### Step 8.4 — Push Image to ECR

```bash
docker push 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest
```

**Expected output:**
```
latest: digest: sha256:abc123... size: 5678
```

### Step 8.5 — Trigger ECS Deployment with New Image

```bash
aws ecs update-service \
  --cluster ledger-stream-cluster-dev \
  --service ledger-stream-service-dev \
  --force-new-deployment \
  --region ap-southeast-2
```

### Step 8.6 — Monitor Deployment

```bash
# Watch ECS logs as service updates
aws logs tail /ecs/ledger-stream-dev --follow --region ap-southeast-2
```

---

## Part 9: Debugging Common Issues

### Issue: Database Connection Failed

**Symptoms:**
```
SQLSTATE[HY000]: General error: 1030 Got error
```

**Solution:**
1. Verify `.env.local` DATABASE_URL is correct
2. Check MySQL is running: `mysql -u root -p -e "SELECT 1"`
3. Verify credentials match your MySQL root password
4. Create database: `php bin/console doctrine:database:create`

### Issue: Migration Fails — Table Already Exists

**Symptoms:**
```
SQLSTATE[42S01]: Base table or view already exists
```

**Solution:**
1. Check if migrations were already applied: `php bin/console doctrine:migrations:list`
2. If stuck, rollback and re-apply:
   ```bash
   php bin/console doctrine:migrations:execute --down DoctrineMigrations\Version20261022120000
   php bin/console doctrine:migrations:migrate
   ```
3. Or reset database (WARNING: deletes data):
   ```bash
   php bin/console doctrine:database:drop --force
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   ```

### Issue: Composer Dependency Conflict

**Symptoms:**
```
Your requirements could not be resolved to an installable set of packages.
```

**Solution:**
1. Clear composer cache: `composer clear-cache`
2. Remove lock file and reinstall: `rm composer.lock && composer install`
3. Update dependencies: `composer update`

### Issue: Docker Build Fails — Phase Fails

**Symptoms:**
```
ERROR: failed to solve with frontend dockerfile.v0
```

**Solution:**
1. Ensure Dockerfile paths are correct: `Dockerfile` should be in `app/ledger-php/`
2. Clean Docker cache: `docker system prune -a`
3. Rebuild without cache: `docker build --no-cache -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php`

### Issue: Tests Fail with "DB Connection Error"

**Symptoms:**
```
SQLSTATE: Connection refused
```

**Solution:**
1. Verify MySQL is running (in docker-compose or locally)
2. Ensure test environment uses correct DSN in `phpunit.xml.dist`:
   ```xml
   <env name="DATABASE_URL" value="mysql://root:rootpassword@127.0.0.1:3306/ledger_test"/>
   ```
3. Create test database manually if needed:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS ledger_test CHARACTER SET utf8mb4"
   ```

---

## Quick Reference Cheat Sheet

| Task | Command |
|---|---|
| Install dependencies | `cd app/ledger-php && composer install` |
| Run migrations | `php bin/console doctrine:migrations:migrate` |
| Start dev server | `symfony server:start` |
| Run tests | `php bin/phpunit` |
| Run specific test | `php bin/phpunit tests/Controller/TransferControllerTest.php` |
| Build Docker image | `docker build -f app/ledger-php/Dockerfile -t ledger-stream-php:latest app/ledger-php` |
| Run docker-compose | `docker-compose up --build` |
| Stop containers | `docker-compose down` |
| View container logs | `docker-compose logs -f ledger_php` |
| Test API | `curl -X POST http://localhost:8000/api/v1/transfers ...` |
| Lint PHP | `php -l src/Service/TransferService.php` |
| Tag for ECR | `docker tag ledger-stream-php:latest 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest` |
| Push to ECR | `docker push 11111.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest` |
| Deploy ECS | `aws ecs update-service --cluster ledger-stream-cluster-dev --service ledger-stream-service-dev --force-new-deployment --region ap-southeast-2` |
| Monitor logs | `aws logs tail /ecs/ledger-stream-dev --follow --region ap-southeast-2` |

---

## Next Steps

- Refer to **AGENTS.md** for architecture overview and deployment workflows
- Refer to **README.md** for detailed system design and API specifications
- Check **Dockerfile** for multi-stage build stages (dev, test, prod)
- Explore **terraform/** directory for infrastructure definition
