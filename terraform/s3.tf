# S3 Bucket for Audit Statements and Settlement Reports
resource "aws_s3_bucket" "audit_statements" {
  bucket        = "ledger-audit-statements-${var.environment}-${data.aws_caller_identity.current.account_id}"
  force_destroy = var.environment == "dev" # Allows clean destroy in dev, protects prod

  tags = {
    Name = "ledger-audit-statements-${var.environment}"
  }
}

# Fetch current AWS Account ID dynamically
data "aws_caller_identity" "current" {}

# Enable versioning for historical audit tracking and safety
resource "aws_s3_bucket_versioning" "audit_versioning" {
  bucket = aws_s3_bucket.audit_statements.id
  versioning_configuration {
    status = "Enabled"
  }
}

# Enforce Server-Side Encryption by default
resource "aws_s3_bucket_server_side_encryption_configuration" "audit_encryption" {
  bucket = aws_s3_bucket.audit_statements.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

# Strictly Block Public Access (Fintech Security Baseline)
resource "aws_s3_bucket_public_access_block" "audit_public_block" {
  bucket                  = aws_s3_bucket.audit_statements.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# Output the bucket name for IAM policy binding or application config
output "audit_bucket_name" {
  value       = aws_s3_bucket.audit_statements.id
  description = "S3 bucket name used for storing immutable audit statements and reconciliation reports"
}
