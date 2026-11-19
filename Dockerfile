FROM php:8.4-apache

# Pinned so a rebuild installs the same way; bump deliberately.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN install-php-extensions \
    pdo \
    pdo_mysql \
    mysqli \
    imagick \
    gd \
    intl \
    zip \
    bcmath \
    exif \
    opcache \
    mbstring \
    fileinfo \
    curl

RUN a2enmod rewrite

WORKDIR /var/www/html