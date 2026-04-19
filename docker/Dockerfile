# syntax=docker/dockerfile:1.4
# Multi-arch build for amd64 and arm64

FROM php:8.4-cli-alpine

# Install system dependencies for PHP extensions
# linux-headers is required for sockets extension (provides linux/sock_diag.h)
# su-exec allows dropping privileges after fixing ownership in entrypoint
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    sqlite-dev \
    oniguruma-dev \
    linux-headers

# Install PHP extensions
# Built-in in php:8.4-cli-alpine: ctype, dom, filter, hash, iconv, json, libxml, phar, tokenizer, xml, xmlwriter
# Explicitly installed: pdo, pdo_sqlite, mbstring, sockets, opcache, pcntl
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_sqlite \
    mbstring \
    sockets \
    opcache \
    pcntl

# Configure opcache for production
RUN echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application code
COPY . .

ARG UID

# Create user with same permissions as host and some useful stuff
RUN adduser -u ${UID} --disabled-password --gecos "" appuser && \
    mkdir -p var/cache var/log var/data && \
    chown -R appuser:appuser /app

# Copy entrypoint script
COPY --chmod=755 docker/entrypoint.sh /entrypoint.sh

# Copy sync-env script
COPY --chmod=755 docker/sync-env.sh /app/docker/sync-env.sh

USER appuser

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD pgrep -f "php bin/console irc:connect" || exit 1

ENTRYPOINT ["/entrypoint.sh"]
