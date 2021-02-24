FROM php:7.4-fpm-alpine

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        curl-dev \
        libtool \
        libxml2-dev \
        oniguruma-dev \
    && apk add --no-cache \
        curl \
        git \
        libintl \
        icu \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install \
        curl \
        iconv \
        mbstring \
        pcntl \
        tokenizer \
        xml \
        zip \
        intl \
        soap \
        pdo \
        pdo_mysql \
    && curl -s https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer \
    && apk del -f .build-deps

WORKDIR /var/www
