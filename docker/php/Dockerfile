FROM php:8.4.26-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libxml2-dev libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite dom mbstring zip intl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8.12 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
