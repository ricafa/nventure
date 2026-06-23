FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql pgsql zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 8000

CMD ["sh", "-lc", "composer install && npm install && npm run build && php artisan key:generate --ansi --force && php artisan optimize:clear && php artisan serve --host=0.0.0.0 --port=8000"]
