# syntax=docker/dockerfile:1.4
# Multi-arch build for amd64 and arm64

FROM php:8.5-cli-trixie AS php-base

# Install runtime certificates and compile PHP extensions.
# pdo_mysql uses the built-in mysqlnd driver and does not require external client libraries.
# pcntl is kept for graceful daemon signal handling (SIGTERM, SIGINT).
# sockets is removed as amphp/socket does not require ext-sockets.
# Pre-installed build dependencies ($PHPIZE_DEPS) are purged after extension compilation.
RUN apt-get update && \
    apt-get install -y --no-install-recommends ca-certificates && \
    docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pcntl && \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS && \
    rm -rf /var/lib/apt/lists/*

# Configure PHP for production CLI daemons (I/O-bound: OPcache enabled for CLI, JIT disabled).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit=disable" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit_buffer_size=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "realpath_cache_size=4096K" > /usr/local/etc/php/conf.d/realpath-cache.ini && \
    echo "realpath_cache_ttl=600" >> /usr/local/etc/php/conf.d/realpath-cache.ini && \
    echo 'memory_limit=${PHP_MEMORY_LIMIT:-512M}' > /usr/local/etc/php/conf.d/memory-limit.ini

WORKDIR /app

FROM php-base AS builder

# Composer and unzip are only needed in the builder stage to download and extract dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && \
    apt-get install -y --no-install-recommends unzip && \
    rm -rf /var/lib/apt/lists/*

# Install production dependencies with BuildKit cache
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

# Copy application source and dump authoritative classmap
COPY . .
RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative \
        --no-interaction

FROM php-base AS runtime

ARG UID=1000

# Create non-root application user in Debian and set application directory ownership
RUN useradd -u ${UID} -m -s /bin/sh appuser && \
    chown appuser:appuser /app

# Copy application code and compiled vendor with authoritative classmap
COPY --chown=appuser:appuser . .
COPY --chown=appuser:appuser --from=builder /app/vendor ./vendor

# Ensure runtime directories exist and have proper permissions for appuser
RUN mkdir -p var/cache var/log var/data && \
    chown -R appuser:appuser var

# Copy entrypoint and environment synchronization scripts
COPY --chmod=755 docker/entrypoint.sh /entrypoint.sh
COPY --chmod=755 docker/sync-env.sh /app/docker/sync-env.sh

USER appuser

# HEALTHCHECK: Deferred pending implementation of an application-level healthcheck
# (e.g. php bin/console irc:health). Process-based checks (pgrep) do not verify IRC
# service health and introduce unwanted dependencies into minimal images.

ENTRYPOINT ["/entrypoint.sh"]

CMD ["php", "bin/console", "irc:connect"]
