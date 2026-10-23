# 1. Generate Database Password (URL-safe + RDS-compatible)
resource "random_password" "db_password" {
  length      = 32
  special     = true
  override_special = "!#$%&*-_=+"  # Only URL-safe special chars: exclude /, @, ", space, ^, |, {, }
}

# 2. Database Subnet Group
resource "aws_db_subnet_group" "ledger_db_subnet_group" {
  name        = "ledger-stream-db-subnet-group-${var.environment}"
  subnet_ids  = [aws_subnet.private_a.id, aws_subnet.private_b.id]

  tags = {
    Name = "ledger-stream-db-subnet-group"
  }
}

# 3. Security Group for RDS
resource "aws_security_group" "rds_sg" {
  name        = "ledger-stream-rds-sg-${var.environment}"
  description = "Security group for Ledger Stream MySQL RDS"
  vpc_id      = aws_vpc.main.id

  ingress {
    from_port       = 3306
    to_port         = 3306
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs_sg.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "ledger-stream-rds-sg"
  }
}

# 4. RDS MySQL Instance with Generated Password
resource "aws_db_instance" "ledgerdb" {
  identifier                  = "ledger-stream-db-${var.environment}"
  engine                      = "mysql"
  engine_version              = "8.0"
  instance_class              = "db.t3.micro"
  allocated_storage           = 20
  max_allocated_storage       = 100
  storage_type                = "gp2"

  db_name                     = "ledgerdb"
  username                    = "ledgerstream"
  password                    = random_password.db_password.result

  db_subnet_group_name        = aws_db_subnet_group.ledger_db_subnet_group.name
  vpc_security_group_ids      = [aws_security_group.rds_sg.id]

  skip_final_snapshot         = var.environment == "prod" ? false : true
  publicly_accessible         = false

  tags = {
    Name = "ledger-stream-mysql"
  }
}

# 5. Create Secrets Manager Secret with Complete DATABASE_URL
resource "aws_secretsmanager_secret" "database_url" {
  name                    = "ledger-stream/database-url-${var.environment}"
  recovery_window_in_days = var.environment == "prod" ? 7 : 0
}

resource "aws_secretsmanager_secret_version" "database_url" {
  secret_id      = aws_secretsmanager_secret.database_url.id
  secret_string  = "mysql://ledgerstream:${random_password.db_password.result}@${aws_db_instance.ledgerdb.endpoint}/${aws_db_instance.ledgerdb.db_name}?serverVersion=8.0&charset=utf8mb4"
  depends_on     = [aws_db_instance.ledgerdb]
}

# 6. Outputs
output "rds_endpoint" {
  value       = aws_db_instance.ledgerdb.endpoint
  description = "Database connection endpoint"
}

output "database_url_secret_arn" {
  value       = aws_secretsmanager_secret.database_url.arn
  description = "AWS Secrets Manager ARN containing the complete DATABASE_URL"
}
