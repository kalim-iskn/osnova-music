FROM php:8.5-cli-alpine AS vendor
WORKDIR /app

RUN apk add --no-cache git unzip
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM node:22-alpine AS frontend
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php:8.5-fpm-alpine
WORKDIR /var/www/html

RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-client \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        zlib-dev \
    && docker-php-ext-install pdo_pgsql intl bcmath \
    && mkdir -p /run/nginx /var/log/supervisor

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor.d/supervisord.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint artisan \
    && chown -R www-data:www-data storage bootstrap/cache \
    && composer dump-autoload --no-dev --optimize

EXPOSE 80
ENTRYPOINT ["entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor.d/supervisord.ini"]
