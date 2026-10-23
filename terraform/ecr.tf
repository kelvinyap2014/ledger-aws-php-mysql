resource "aws_ecr_repository" "ledger_stream" {
  name                 = "ledger-stream-php"
  image_tag_mutability = "MUTABLE"
  force_delete         = false

  image_scanning_configuration {
    scan_on_push = true
  }
}
