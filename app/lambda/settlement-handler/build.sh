#!/bin/bash

# Settlement Handler Lambda Build Script
# Builds a lean Lambda deployment artifact using Docker (prod dependencies only)

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "🔨 Building NestJS Lambda Settlement Handler..."
echo "   Directory: $SCRIPT_DIR"

# Clean up old artifacts
echo ""
echo "🧹 Cleaning up old artifacts..."
rm -f function.zip
echo "✓ Removed old function.zip (if it existed)"

# Build Docker image
echo ""
echo "📦 Building Docker image (multi-stage build)..."
docker build -t settlement-handler-builder -f Dockerfile.build . --progress=plain

# Extract the zip artifact to root directory
echo ""
echo "📤 Extracting function.zip..."
docker run --rm settlement-handler-builder cat /app/function.zip > function.zip

if [ -f "function.zip" ] && [ -s "function.zip" ]; then
  echo "✅ Lambda artifact ready!"
  ls -lh function.zip
  echo ""
  echo "📋 Archive contents (first 20 entries):"
  unzip -l function.zip | head -20
  echo ""
  echo "✨ Ready to deploy: function.zip"
  exit 0
fi

echo "❌ Failed to create artifact"
exit 1
