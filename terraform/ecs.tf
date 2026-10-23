# 1. ECS Cluster
resource "aws_ecs_cluster" "main" {
  name = "ledger-stream-cluster-${var.environment}"
}

# 2. CloudWatch Log Group for ECS Container Logs
resource "aws_cloudwatch_log_group" "ecs_logs" {
  name              = "/ecs/ledger-stream-${var.environment}"
  retention_in_days = 30
}

# 3. ECS Task Definition
resource "aws_ecs_task_definition" "app" {
  family                   = "ledger-stream-${var.environment}"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.ecs_task_cpu
  memory                   = var.ecs_task_memory
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name      = "ledger-stream"
      image     = "677675386589.dkr.ecr.ap-southeast-2.amazonaws.com/ledger-stream-php:latest"
      essential = true
      portMappings = [
        {
          containerPort = 8080
          protocol      = "tcp"
        }
      ]
      environment = [
        # { name = "SQS_SETTLEMENT_QUEUE_URL", value = aws_sqs_queue.settlement_queue.url },
        # { name = "AWS_S3_AUDIT_BUCKET_NAME", value = aws_s3_bucket.audit_statements.id },
        { name = "AWS_REGION", value = var.region }
      ]
      secrets = [
        { name = "DATABASE_URL", valueFrom = aws_secretsmanager_secret.database_url.arn }
      ]
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.ecs_logs.name
          "awslogs-region"        = var.region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

# 4. ECS Service
resource "aws_ecs_service" "app" {
  name            = "ledger-stream-service-${var.environment}"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = [aws_subnet.private_a.id, aws_subnet.private_b.id]
    security_groups  = [aws_security_group.ecs_sg.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "ledger-stream"
    container_port   = 8080
  }

  depends_on = [aws_lb_listener.http]
}

# ==========================================
# 5. IAM Roles for ECS Execution & Tasks
# ==========================================

resource "aws_iam_role" "ecs_execution_role" {
  name = "ledger-stream-ecs-execution-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_execution_role_policy" {
  role       = aws_iam_role.ecs_execution_role.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# Grant execution role permission to read from Secrets Manager
resource "aws_iam_policy" "ecs_secrets_policy" {
  name = "ledger-stream-ecs-secrets-policy-${var.environment}"

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "secretsmanager:GetSecretValue"
        ]
        Resource = [
          aws_secretsmanager_secret.database_url.arn
        ]
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_execution_secrets_attach" {
  role       = aws_iam_role.ecs_execution_role.name
  policy_arn = aws_iam_policy.ecs_secrets_policy.arn
}

resource "aws_iam_role" "ecs_task_role" {
  name = "ledger-stream-ecs-task-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
    }]
  })
}

# Grant task role permission to send messages to SQS
# resource "aws_iam_policy" "ecs_sqs_policy" {
#   name = "ledger-stream-ecs-sqs-policy-${var.environment}"

#   policy = jsonencode({
#     Version = "2012-10-17"
#     Statement = [
#       {
#         Effect = "Allow"
#         Action = [
#           "sqs:SendMessage"
#         ]
#         Resource = aws_sqs_queue.settlement_queue.arn
#       }
#     ]
#   })
# }

# resource "aws_iam_role_policy_attachment" "ecs_task_sqs_attach" {
#   role       = aws_iam_role.ecs_task_role.name
#   policy_arn = aws_iam_policy.ecs_sqs_policy.arn
# }

# Grant task role permission to read/write to S3 audit bucket
# resource "aws_iam_policy" "ecs_s3_policy" {
#   name = "ledger-stream-ecs-s3-policy-${var.environment}"

#   policy = jsonencode({
#     Version = "2012-10-17"
#     Statement = [
#       {
#         Effect = "Allow"
#         Action = [
#           "s3:PutObject",
#           "s3:GetObject",
#           "s3:ListBucket"
#         ]
#         Resource = [
#           aws_s3_bucket.audit_statements.arn,
#           "${aws_s3_bucket.audit_statements.arn}/*"
#         ]
#       }
#     ]
#   })
# }

# resource "aws_iam_role_policy_attachment" "ecs_task_s3_attach" {
#   role       = aws_iam_role.ecs_task_role.name
#   policy_arn = aws_iam_policy.ecs_s3_policy.arn
# }
