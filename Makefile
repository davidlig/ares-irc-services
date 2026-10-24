.PHONY: help build build-no-cache up down logs logs-tail ps shell clean restart test coverage db-migrate health config build-multiarch

# Detect docker compose command (v2 uses 'docker compose', v1 uses 'docker-compose')
DOCKER_COMPOSE ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
UID := $(shell id -u)
# Production adds host networking without changing local Compose defaults.
COMPOSE_FILES := -f docker/compose.yaml $(if $(ARES_COMPOSE_OVERRIDE),-f $(ARES_COMPOSE_OVERRIDE))

TEST_ARGS ?=

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

build: ## Build Docker image
	UID=$(UID) $(DOCKER_COMPOSE) $(COMPOSE_FILES) build

build-no-cache: ## Build without cache
	UID=$(UID) $(DOCKER_COMPOSE) $(COMPOSE_FILES) build --no-cache

config: ## Create .env.local from .env template
	@./scripts/init-env.sh

up: ## Start services in background (always rebuilds image)
	@if [ ! -f .env.local ]; then \
		echo "❌ .env.local not found. Run 'make config' first to create it."; \
		exit 1; \
	fi
	@mkdir -p var/data var/log
	UID=$(UID) $(DOCKER_COMPOSE) $(COMPOSE_FILES) up -d --build

down: ## Stop services
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) down

logs: ## Follow logs in real-time
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) logs -f ares

logs-tail: ## Show last 100 lines of logs
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) logs --tail=100 ares

ps: ## Show container status
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) ps

shell: ## Open shell inside container
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) exec ares /bin/sh

clean: ## DANGER: remove containers, volumes, images, caches and logs
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) down -v --rmi local
	rm -rf var/cache/* var/log/*

restart: down up ## Restart services

# Database operations
db-migrate: ## Run migrations manually
	$(DOCKER_COMPOSE) $(COMPOSE_FILES) exec ares php bin/console doctrine:migrations:migrate -n

test: ## Run PHPUnit without coverage (TEST_ARGS='tests/path --filter name')
	@./vendor/bin/phpunit --no-coverage --display-all-issues $(TEST_ARGS)

coverage: ## Run the complete exact-100% coverage gate
	@./scripts/check-coverage.sh 100 --issues

health: ## Fail unless the container is present and healthy
	@container_id="$$($(DOCKER_COMPOSE) $(COMPOSE_FILES) ps -q ares)" || exit 1; \
		[ -n "$$container_id" ] || { echo "❌ Container absent"; exit 1; }; \
		status="$$(docker inspect --format '{{.State.Health.Status}}' "$$container_id" 2>/dev/null)" || exit 1; \
		if [ "$$status" = "healthy" ]; then \
			echo "✅ Healthy"; \
		else \
			echo "❌ Unhealthy"; exit 1; \
		fi

# Multi-arch build
build-multiarch: ## Build multi-arch image (amd64 + arm64)
	@./docker/docker-buildx.sh
