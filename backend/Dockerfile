# SGP backend image (plan S1.2: reproducible build, no dev deps in runtime).
# Build context: backend/   ->   docker build -t sgp-backend .

# ---------------------------------------------------------------------------
# Stage 1: install locked production dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --audit

# ---------------------------------------------------------------------------
# Stage 2: runtime
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# Production extensions: MySQL driver, bcmath for exact money math (RNF-008),
# opcache for performance (RNF-001).
RUN docker-php-ext-install pdo_mysql bcmath opcache

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

ENV APP_ENV=production \
    APP_DEBUG=false \
    RUNTIME_SKIP_CONFIG_CACHE=true

# Non-root runtime user
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
