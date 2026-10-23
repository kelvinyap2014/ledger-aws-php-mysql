# IAM Role for Go Lambda
resource "aws_iam_role" "lambda_exec" {
  name = "${var.project_name}-settlement-lambda-role-${var.environment}"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = { Service = "lambda.amazonaws.com" }
    }]
  })
}

# Attach basic execution policy for CloudWatch Logs
resource "aws_iam_role_policy_attachment" "lambda_basic" {
  role       = aws_iam_role.lambda_exec.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole"
}

# Attach SQS execution policy so Lambda can read and delete messages from the queue
resource "aws_iam_role_policy_attachment" "lambda_sqs" {
  role       = aws_iam_role.lambda_exec.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaSQSQueueExecutionRole"
}

# AWS Lambda Function
resource "aws_lambda_function" "settlement_handler" {
  filename         = "../app/lambda/settlement-handler/function.zip"
  function_name    = "${var.project_name}-settlement-handler-${var.environment}"
  role             = aws_iam_role.lambda_exec.arn
  handler          = "dist/handler.handler"  # NestJS bundled handler entry point
  runtime          = "nodejs22.x"            # Node.js runtime
  source_code_hash = filebase64sha256("../app/lambda/settlement-handler/function.zip")
  timeout          = 60
  environment {
    variables = {
      ENVIRONMENT = var.environment
    }
  }

  tags = {
    Name = "${var.project_name}-settlement-handler-${var.environment}"
  }
}

# Event Source Mapping (Triggers Lambda from SQS batches)
resource "aws_lambda_event_source_mapping" "sqs_trigger" {
  event_source_arn = aws_sqs_queue.settlement_queue.arn
  function_name    = aws_lambda_function.settlement_handler.arn
  batch_size       = 10 # Processes up to 10 messages per batch
  enabled          = true
}
