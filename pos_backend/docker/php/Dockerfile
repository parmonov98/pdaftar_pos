# pDaftar POS — PHP-FPM.
#
# The POS's own, not pDaftar's. It used to build from ../../backend/docker/php,
# which meant a till could not be built without a checkout of a different
# product sitting next to it.
#
# Deliberately smaller than the one it replaces. pDaftar's image carries
# ImageMagick with the HEIF delegate (iPhone photo uploads), pecl_http and
# raphf — none of which a till uses. Dropping them takes the build from minutes
# to under one on a 2 GB instance, which matters because this image is built on
# the server during provisioning.
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# GD needs an explicit --with-jpeg or it silently builds without JPEG support;
# libjpeg62-turbo-dev alone is not enough. The till prints receipts with logos.
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Sessions, cache and queue all run through Redis.
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# The repository is mounted, not copied, and its owner on the host is not the
# container's user. Without this git refuses to read it and `composer install`
# fails on any package it wants version information for.
RUN git config --global --add safe.directory /var/www

WORKDIR /var/www
