.PHONY: help build build-no-cache up down logs logs-tail ps shell clean restart test db-shell db-migrate db-backup db-restore config-show health

# Detect docker compose command (v2 uses 'docker compose', v1 uses 'docker-compose')
DOCKER_COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

BACKUP_DIR ?= backups
BACKUP_FILE = ares-$(shell date +%Y%m%d-%H%M%S).db

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

build: ## Build Docker image
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml build

build-no-cache: ## Build without cache
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml build --no-cache

up: ## Start services in background (always rebuilds image)
	@./scripts/init-env.sh
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml up -d --build

down: ## Stop services
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml down

logs: ## Follow logs in real-time
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml logs -f ares

logs-tail: ## Show last 100 lines of logs
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml logs --tail=100 ares

ps: ## Show container status
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml ps

shell: ## Open shell inside container
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml exec ares /bin/sh

clean: ## Remove containers, volumes, and images
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml down -v --rmi local
	rm -rf var/cache/* var/log/*

restart: down up ## Restart services

# Database operations
db-shell: ## Open SQLite shell
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml exec ares php bin/console dbal:run-sql

db-migrate: ## Run migrations manually
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml exec ares php bin/console doctrine:migrations:migrate -n

db-backup: ## Backup database to backups/ directory
	@mkdir -p $(BACKUP_DIR)
	@cp var/data/ares.db $(BACKUP_DIR)/$(BACKUP_FILE)
	@echo "✅ Backup created: $(BACKUP_DIR)/$(BACKUP_FILE)"

db-restore: ## Restore database from backups/ (usage: make db-restore FILE=backups/ares-20240101-120000.db)
	@if [ -z "$(FILE)" ]; then \
		echo "❌ Usage: make db-restore FILE=backups/ares-YYYYMMDD-HHMMSS.db"; \
		exit 1; \
	fi
	@if [ ! -f "$(FILE)" ]; then \
		echo "❌ File not found: $(FILE)"; \
		exit 1; \
	fi
	@$(DOCKER_COMPOSE) -f docker/docker-compose.yml down
	@cp $(FILE) var/data/ares.db
	@$(DOCKER_COMPOSE) -f docker/docker-compose.yml up -d
	@echo "✅ Database restored from: $(FILE)"

config-show: ## Show current configuration
	$(DOCKER_COMPOSE) -f docker/docker-compose.yml exec ares php bin/console debug:dotenv

health: ## Check container health
	@$(DOCKER_COMPOSE) -f docker/docker-compose.yml exec ares pgrep -f "php bin/console irc:connect" > /dev/null 2>&1 && echo "✅ Healthy" || echo "❌ Unhealthy"

# Multi-arch build
build-multiarch: ## Build multi-arch image (amd64 + arm64)
	@./docker/docker-buildx.sh
