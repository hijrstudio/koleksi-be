# syntax=docker/dockerfile:1
# Image produksi backend (Laravel + FrankenPHP). Satu container menjalankan web, queue worker & scheduler
# lewat supervisord (docker/supervisord.conf), karena VPS OpenVZ membatasi jumlah thread per VPS.

# 1) Dependensi PHP
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# 2) Aset CMS (Vite + Tailwind). Tailwind men-scan view pagination dari vendor (@source di resources/css/app.css).
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# 3) Runtime
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql redis intl zip gd bcmath opcache pcntl exif \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && apt-get update && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && sed -i 's/\r$//' docker/entrypoint.sh docker/supervisord.conf && chmod +x docker/entrypoint.sh \
    && useradd --uid 1000 --create-home app \
    && chown -R app:app storage bootstrap/cache public /config/caddy /data/caddy

# Hemat thread: runtime Go FrankenPHP cukup 2 CPU (jumlah thread PHP diatur di docker/Caddyfile)
ENV GOMAXPROCS=2 \
    APP_BIND=0.0.0.0 \
    APP_PORT=8080 \
    RUN_QUEUE=true \
    RUN_SCHEDULER=true

USER app
EXPOSE 8080
# Healthcheck bawaan image memakai admin API :2019 yang dimatikan di docker/Caddyfile
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${APP_PORT}/up" || exit 1
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["supervisord", "-c", "/app/docker/supervisord.conf"]
