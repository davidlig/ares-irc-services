#!/bin/bash
set -e

echo "==> Setting up multi-arch builder..."

# Create or use existing builder
docker buildx create --name ares-builder --use 2>/dev/null || docker buildx use ares-builder
docker buildx inspect --bootstrap

echo "==> Building multi-arch image (amd64, arm64)..."

# Build and load for current platform
docker buildx build \
    --platform linux/amd64,linux/arm64 \
    --tag ares-irc-services:latest \
    --load \
    .

echo "✅ Multi-arch build complete"
