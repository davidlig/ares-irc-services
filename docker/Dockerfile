# syntax=docker/dockerfile:1.4
# Multi-arch build for amd64 and arm64

FROM php:8.5-cli-alpine AS php-base

# Install system dependencies for PHP extensions.
# linux-headers is required for sockets extension (provides linux/sock_diag.h)
# mariadb-dev provides MySQL/MariaDB client headers for pdo_mysql extension
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    linux-headers \
    mariadb-dev

# Install PHP extensions
# Built-in in php:8.5-cli-alpine: ctype, curl, dom, filter, hash, iconv, json, mbstring, pdo_sqlite, opcache, sqlite3, xml, xmlwriter
# Explicitly installed: pdo_mysql, sockets, pcntl
RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    sockets \
    pcntl

# Configure PHP for production CLI daemons.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.jit=tracing" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "realpath_cache_size=4096K" > /usr/local/etc/php/conf.d/realpath-cache.ini && \
    echo "realpath_cache_ttl=600" >> /usr/local/etc/php/conf.d/realpath-cache.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM php-base AS vendor

# Install dependencies before copying the full source tree so Docker can reuse
# the Composer layer when only application code changes.
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

FROM php-base AS runtime

ARG UID=1000

# Create user with same permissions as host and some useful stuff
RUN adduser -u ${UID} --disabled-password --gecos "" appuser

# Copy application code and cached production dependencies with final ownership.
COPY --chown=appuser:appuser . .
COPY --chown=appuser:appuser --from=vendor /app/vendor ./vendor

# Rebuild the optimized classmap after source code is present, then fix runtime
# write paths for the non-root user.
RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative \
        --no-interaction && \
    mkdir -p var/cache var/log var/data && \
    chown -R appuser:appuser var

# Copy entrypoint script
COPY --chmod=755 docker/entrypoint.sh /entrypoint.sh

# Copy sync-env script
COPY --chmod=755 docker/sync-env.sh /app/docker/sync-env.sh

USER appuser

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD pgrep -f "php bin/console irc:connect" || exit 1

ENTRYPOINT ["/entrypoint.sh"]

CMD ["php", "bin/console", "irc:connect"]
