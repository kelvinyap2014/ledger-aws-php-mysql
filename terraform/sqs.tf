# SQS Settlement Queue
resource "aws_sqs_queue" "settlement_queue" {
  name                       = "ledger-settlement-queue-${var.environment}"
  visibility_timeout_seconds = 60
  message_retention_seconds  = 345600 # 4 days

  tags = {
    Name = "ledger-settlement-queue-${var.environment}"
  }
}

# VPC Endpoint for SQS - allows private subnet access without NAT gateway
resource "aws_vpc_endpoint" "sqs" {
  vpc_id              = aws_vpc.main.id
  service_name        = "com.amazonaws.${var.region}.sqs"
  vpc_endpoint_type   = "Interface"
  private_dns_enabled = true

  subnet_ids         = [aws_subnet.private_a.id, aws_subnet.private_b.id]
  security_group_ids = [aws_security_group.vpc_endpoint_sg.id]

  tags = {
    Name = "ledger-stream-sqs-endpoint-${var.environment}"
  }
}

# Security group for VPC Endpoint - allow ECS tasks to connect
resource "aws_security_group" "vpc_endpoint_sg" {
  name        = "ledger-stream-sqs-endpoint-sg-${var.environment}"
  description = "Security group for SQS VPC endpoint"
  vpc_id      = aws_vpc.main.id

  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["10.0.0.0/16"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "ledger-stream-sqs-endpoint-sg-${var.environment}"
  }
}

output "sqs_settlement_queue_url" {
  value       = aws_sqs_queue.settlement_queue.url
  description = "URL of the SQS settlement queue for Spring Boot configuration"
}

output "sqs_vpc_endpoint_id" {
  value       = aws_vpc_endpoint.sqs.id
  description = "VPC Endpoint ID for SQS"
}
