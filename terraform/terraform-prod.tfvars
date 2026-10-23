region       = "ap-southeast-2"
environment  = "prod"
project_name = "ledger-stream"
ecs_task_cpu    = "2048"   # Higher CPU for production performance
ecs_task_memory = "4096"  # 4 GB RAM to comfortably handle JVM and database connection pools
