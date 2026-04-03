#!/bin/bash
# Initialize .env.local from .env if it doesn't exist
# This is required for Docker bind mount to work
# APP_SECRET generation is delegated to the container (where PHP is guaranteed)

set -e

ENV_FILE=".env.local"
TEMPLATE_FILE=".env"

if [ ! -f "$ENV_FILE" ]; then
    echo "==> Creating $ENV_FILE from template"

    if [ ! -f "$TEMPLATE_FILE" ]; then
        echo "ERROR: $TEMPLATE_FILE not found"
        exit 1
    fi

    cp "$TEMPLATE_FILE" "$ENV_FILE"

    echo "==> $ENV_FILE created from template"
    echo ""
    echo "==> IMPORTANT: Edit $ENV_FILE and configure your IRCD connection:"
    echo "    IRC_IRCD_HOST=host.docker.internal  (Docker Desktop)"
    echo "    IRC_IRCD_HOST=172.17.0.1           (Linux)"
    echo "    IRC_SERVER_NAME=services.example.com"
    echo "    IRC_LINK_PASSWORD=your-secret-password"
    echo ""
    echo "==> APP_SECRET will be generated automatically on first container start"
fi
