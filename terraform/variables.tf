variable "region" {
  type        = string
  description = "AWS region for deploying resources"
  default     = "ap-southeast-2"
}

variable "environment" {
  type        = string
  description = "Target deployment environment (e.g., dev, staging, prod)"
  default     = "dev"
}

variable "project_name" {
  type        = string
  description = "Name of the project used for tagging and resource naming"
  default     = "ledger-stream"
}

variable "ecs_task_cpu" {
  type        = string
  description = "Fargate task CPU units (e.g., 256 for 0.25 vCPU, 512 for 0.5 vCPU)"
  default     = "256"
}

variable "ecs_task_memory" {
  type        = string
  description = "Fargate task memory in MB (e.g., 512, 1024)"
  default     = "512"
}
